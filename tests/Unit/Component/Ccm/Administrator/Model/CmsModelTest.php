<?php
namespace Reem\Tests\Unit\Component\CCM\Administrator\Model;

use Joomla\Tests\Unit\UnitTestCase;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Reem\Component\CCM\Administrator\Model\CmsModel;

class CmsModelTest extends UnitTestCase
{
    public function testGetFormReturnsFormObject()
    {
        $model = $this->getMockBuilder(CmsModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadForm'])
            ->getMock();

        $formMock = $this->createMock(Form::class);
        $model->expects($this->once())
            ->method('loadForm')
            ->willReturn($formMock);

        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('getForm');
        $method->setAccessible(true);

        $result = $method->invoke($model);
        $this->assertSame($formMock, $result);
    }

    public function testGetFormReturnsFalseIfFormEmpty()
    {
        $model = $this->getMockBuilder(CmsModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadForm'])
            ->getMock();

        $model->expects($this->once())
            ->method('loadForm')
            ->willReturn(false);

        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('getForm');
        $method->setAccessible(true);

        $result = $method->invoke($model);
        $this->assertFalse($result);
    }

    public function testLoadFormDataReturnsArrayWithMappingDecoded()
    {
        $model = $this->getMockBuilder(CmsModel::class)
            ->disableOriginalConstructor()    
            ->onlyMethods(['getItem'])
            ->getMock();

        $item = (object)[
            'ccm_mapping' => json_encode(['foo' => 'bar']),
        ];

        $model->method('getItem')->willReturn($item);

        // Simulate empty user state
        $app = $this->createMock(\Joomla\CMS\Application\CMSApplication::class);
        $app->method('getUserState')->willReturn([]);
        Factory::$application = $app;

        $reflection = new \ReflectionClass($model);
        $method = $reflection->getMethod('loadFormData');
        $method->setAccessible(true);

        $result = $method->invoke($model);

        $this->assertIsObject($result);
        $this->assertEquals(['foo' => 'bar'], $result->ccm_mapping);
    }
}