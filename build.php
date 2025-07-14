<?php
/**
 * Build script for Component Migration Manager
 *
 * @package     CCM
 * @author      Joomla! Project
 * @copyright   2025 Joomla! Project
 * @license     GNU General Public License version 2 or later
 */

class ComponentBuilder
{
    private $version = '1.0.0';
    private $packageName = 'com_ccm';
    private $buildDir = 'dist';
    private $sourceDir = 'src';
    
    public function __construct()
    {
        echo "Component Migration Manager Build Script\n";
        echo "========================================\n";
    }
    
    public function build()
    {
        // Create build directory
        $this->createBuildDir();
        
        // Copy component files
        $this->copyComponentFiles();
        
        // Process version replacement
        $this->replaceVersions();
        
        // Create ZIP package
        $this->createPackage();
        
        echo "\nBuild completed successfully!\n";
        echo "Package: {$this->buildDir}/{$this->packageName}-{$this->version}.zip\n";
    }
    
    private function createBuildDir()
    {
        $buildPath = "{$this->buildDir}/{$this->packageName}-{$this->version}";
        
        if (is_dir($buildPath)) {
            $this->deleteDirectory($buildPath);
        }
        
        if (!is_dir($this->buildDir)) {
            mkdir($this->buildDir, 0755, true);
        }
        
        mkdir($buildPath, 0755, true);
        
        echo "Created build directory: $buildPath\n";
    }
    
    private function copyComponentFiles()
    {
        $buildPath = "{$this->buildDir}/{$this->packageName}-{$this->version}";
        
        // Copy component manifest file (rename to ccm.xml for component)
        if (file_exists('component_manifest.xml')) {
            copy('component_manifest.xml', "$buildPath/ccm.xml");
            echo "Copied component manifest as ccm.xml\n";
        } elseif (file_exists('manifest.xml')) {
            copy('manifest.xml', "$buildPath/manifest.xml");
            echo "Copied manifest.xml\n";
        }
        
        // Copy package script if it exists
        if (file_exists('script.php')) {
            copy('script.php', "$buildPath/script.php");
            echo "Copied script.php\n";
        }
        
        // Copy administrator component
        $adminSource = "{$this->sourceDir}/administrator/components/com_ccm";
        $adminDest = "$buildPath/administrator/components/com_ccm";
        
        if (is_dir($adminSource)) {
            $this->copyDirectory($adminSource, $adminDest);
            echo "Copied administrator component\n";
        }
        
        // Copy site component if it exists
        $siteSource = "{$this->sourceDir}/components/com_ccm";
        $siteDest = "$buildPath/components/com_ccm";
        
        if (is_dir($siteSource)) {
            $this->copyDirectory($siteSource, $siteDest);
            echo "Copied site component\n";
        }
        
        // Copy media files if they exist
        $mediaSource = "{$this->sourceDir}/media/com_ccm";
        $mediaDest = "$buildPath/media/com_ccm";
        
        if (is_dir($mediaSource)) {
            $this->copyDirectory($mediaSource, $mediaDest);
            echo "Copied media files\n";
        }
        
        // Copy language files
        $this->copyLanguageFiles($buildPath);
    }
    
    private function copyLanguageFiles($buildPath)
    {
        // Administrator language files
        $adminLangSource = "{$this->sourceDir}/administrator/language";
        $adminLangDest = "$buildPath/administrator/language";
        
        if (is_dir($adminLangSource)) {
            $this->copyDirectory($adminLangSource, $adminLangDest);
            echo "Copied administrator language files\n";
        }
        
        // Site language files
        $siteLangSource = "{$this->sourceDir}/language";
        $siteLangDest = "$buildPath/language";
        
        if (is_dir($siteLangSource)) {
            $this->copyDirectory($siteLangSource, $siteLangDest);
            echo "Copied site language files\n";
        }
    }
    
    private function replaceVersions()
    {
        $buildPath = "{$this->buildDir}/{$this->packageName}-{$this->version}";
        
        // Find all PHP and XML files and replace version placeholders
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($buildPath),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.(php|xml)$/', $file->getFilename())) {
                $content = file_get_contents($file->getPathname());
                $content = str_replace('__DEPLOY_VERSION__', $this->version, $content);
                file_put_contents($file->getPathname(), $content);
            }
        }
        
        echo "Replaced version placeholders\n";
    }
    
    private function createPackage()
    {
        $buildPath = "{$this->buildDir}/{$this->packageName}-{$this->version}";
        $zipFile = "{$this->buildDir}/{$this->packageName}-{$this->version}.zip";
        
        // Remove existing zip file if it exists
        if (file_exists($zipFile)) {
            unlink($zipFile);
        }
        
        // Use system zip command
        $command = "cd '$buildPath' && zip -r '../{$this->packageName}-{$this->version}.zip' . -x '*.DS_Store' '*.git*'";
        $result = exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Failed to create ZIP package. Command: $command");
        }
        
        echo "Created ZIP package: $zipFile\n";
    }
    
    private function copyDirectory($source, $dest)
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destPath = $dest . DIRECTORY_SEPARATOR . $relativePath;
            
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item, $destPath);
            }
        }
    }
    
    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        
        rmdir($dir);
    }
}

// Run the build
try {
    $builder = new ComponentBuilder();
    $builder->build();
} catch (Exception $e) {
    echo "Build failed: " . $e->getMessage() . "\n";
    exit(1);
}
