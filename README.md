# Content Migration Between CMSs Using CCM & MDE

**Google Summer of Code 2025 Project by Reem Atalah**

## 📋 Overview

The Content Creation Management (CCM) component is a Joomla extension that enables seamless content migration between different Content Management Systems (CMS) using a Common Content Model (CCM) and Model-Driven Engineering (MDE) format. This project facilitates migration between WordPress, Joomla and easily extending other CMS platforms.

## 🚀 Features

- **Multi-CMS Support**: Migrate content between WordPress, Joomla, and more.
- **Standardized Format**: Uses CCM schema for consistent content representation.
- **Metadata Preservation**: Maintains content metadata during migration.
- **User-Friendly Interface**: Intuitive Joomla administrator interface.
- **Comprehensive Testing**: Full test coverage with Cypress (E2E) and PHPUnit (Unit).
- **Simple Migration Feedback**: Displays a success message upon completion or a detailed failure message if migration fails.

## 📁 Project Structure

```
gsoc25_api/
├── src/administrator/components/com_ccm/     # Main component source
│   ├── src/                                  # PHP classes
│   │   ├── Controller/                       # MVC Controllers
│   │   ├── Model/                           # Data models
│   │   ├── View/                            # View classes
│   │   ├── Table/                           # Database tables
│   │   ├── Schema/                          # CMS-CCM mapping 
│   │   └── Service/                         # Service providers
│   ├── tmpl/                                # Template files
│   ├── forms/                               # Form definitions
│   ├── language/                            # Language files
│   └── sql/                                 # Database schema
├── tests/                                   # Test suites
│   ├── System/                              # Cypress E2E tests
│   └── Unit/                                # PHPUnit unit tests
├── cypress.config.mjs                       # Cypress configuration
├── phpunit.xml                             # PHPUnit configuration
└── composer.json                           # PHP dependencies
```

## 🛠️ Prerequisites

- **Joomla 4.0+** instance
- **PHP 8.1+** with MySQLi extension
- **MySQL/MariaDB** database
- **Node.js 16+** and npm
- **Composer** for PHP dependency management

## 📦 Installation & Setup

### 1. Clone the Repository

```bash
git clone https://github.com/joomla-projects/gsoc25_api.git
cd gsoc25_api
```

### 2. Install Dependencies

**PHP Dependencies:**
```bash
composer install
```

**Node.js Dependencies:**
```bash
npm install
```

### 3. Joomla Installation


1. Open your Joomla app
2. Access the administrator panel
3. Navigate to **System > Install > Extensions**
4. Upload the zip file for the component

### 4. Database Setup

The component will automatically create the required database tables upon installation:
- `#__ccm_cms` - Stores CMS configurations
- Initiallizes the CMSs available for migration

## 🔧 Configuration

### Environment Setup for Testing

Update `cypress.config.mjs` with your environment details:

```javascript
env: {
  sitename: 'Your Site Name',
  username: 'your-admin-username',
  password: 'your-admin-password',
  db_host: 'localhost',
  db_name: 'your_joomla_db',
  db_user: 'db_username',
  db_password: 'db_password',
  db_prefix: 'your_prefix_',
}
```

## 📝 How to Use the CCM Component

### 1. Editing a CMS

1. In Joomla Admin, go to **Components > CCM**
2. Choose on of the CMSs
3. Update in the CMS details:
   - **Name**: Descriptive name for the CMS
   - **URL**: Base URL of the source CMS
   - **Credentials**: API keys or authentication details

### 2. Configuring Content Mapping

1. Navigate in the component folder into **src > Schema > ${cms}-ccm**
2. Map content types between a CMS and the CCM:
- **Posts/Articles**: Map WordPress "posts" or Joomla "articles" to the CCM "ContentItem" type.
- **Categories**: Align CMS-specific categories/taxonomies to the CCM "categories" property.
- **Users/Authors**: Map user or author fields to the CCM "author" definition. *(future work)*
- **Media**: Link media files (images, attachments) to the CCM "media" references. *(future work)*
- **Custom Fields**: Extend the mapping for any custom fields or metadata your CMS uses. *(future work)*
- **Example Mapping File** (`schema/wordpress-ccm.json`):
    ```json
    {
        "ContentItem": 
        [
            {
            "type": "posts",
            "properties": {
                    "ID": "id",
                    "post_title": "title",
                    "post_content": "content",
                    "post_status": "status",
                    "post_date": "created",
                }
            }
        ]
    }
    ```

### 3. Running a Migration

1. Navigate to **Components > CCM > Migration**
2. Select source and target CMS
3. Choose content types to migrate:
  - Categories
  - Media files *(future work)*
  - Users *(future work)*
  - Articles/Posts

> **Note:** It is important to migrate the referenced items first for example we need to migrate **categories first**, as articles/posts reference categories. Migrating articles before their referenced categories exist may result in missing or incorrect category assignments. Always follow this order: **Categories → Media files → Users → Articles/Posts**.
4. Click **Apply Migration**
5. Monitor progress in real-time

### 4. Migration Process

The migration follows these steps:

1. **Content Extraction**: Retrieves content from source CMS via API
2. **CCM Conversion**: Transforms content to CCM standard format
3. **Target Conversion**: Adapts CCM format to target CMS structure
4. **Import**: Creates content in target CMS

## 🧪 Testing

### Running Unit Tests (PHPUnit)

```bash
# Run all unit tests
./vendor/bin/phpunit

# Run specific test class
./vendor/bin/phpunit tests/Unit/Ccm/Administrator/Model/CmsModelTest.php
```

### Running E2E Tests (Cypress)

**Prerequisites for E2E Tests:**
- Joomla instance running at `http://localhost:8000`
- Admin user configured in cypress.config.mjs

```bash
# Run all E2E tests
npx cypress run

# Open Cypress GUI for interactive testing
npx cypress open

# Run specific test file
npx cypress run --spec "tests/System/integration/administrator/components/com_ccm/Migration.cy.js"
```

## 🚧 Future Work

- **User and Media Migration**: Implement full support for migrating users/authors and media files between CMSs.
- **Custom Field Mapping**: Enable advanced mapping for custom fields and metadata unique to each CMS.
- **API Discovery**: Automate detection and configuration of available APIs for supported CMSs to simplify setup and integration.
