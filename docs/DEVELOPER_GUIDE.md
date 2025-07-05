# Developer Guide - Content Migration Between CMSs Using CCM & MDE

## 📋 Overview

This guide provides technical details for developers working on the Content Creation Management (CCM) component. The CCM component enables seamless content migration between different Content Management Systems (CMS) using a Common Content Model (CCM) and Model-Driven Engineering (MDE) format.

## 💻 Environment

This project is developed and tested on **Linux Ubuntu**.

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

## 🛠️ Development Prerequisites

- **Joomla 4.0+** instance
- **PHP 8.1+** with MySQLi extension
- **MySQL/MariaDB** database
- **Node.js 16+** and npm
- **Composer** for PHP dependency management

## 📦 Development Setup

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

## 🗺️ Content Mapping Configuration

### Configuring Content Mapping

1. Navigate in the component folder into **src > Schema > ${cms}-ccm**
2. Map content types between a CMS and the CCM:
   - **Posts/Articles**: Map WordPress "posts" or Joomla "articles" to the CCM "ContentItem" type.
   - **Categories**: Align CMS-specific categories/taxonomies to the CCM "categories" property.
   - **Users/Authors**: Map user or author fields to the CCM "author" definition. *(future work)*
   - **Media**: Link media files (images, attachments) to the CCM "media" references. *(future work)*
   - **Custom Fields**: Extend the mapping for any custom fields or metadata your CMS uses. *(future work)*

### Example Mapping File

**Example Mapping File** (`schema/wordpress-ccm.json`):
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

## 🧰 Development Notes

### Architecture

The CCM component follows Joomla's MVC (Model-View-Controller) architecture pattern:

- **Controllers**: Handle user requests and coordinate between models and views
- **Models**: Manage data logic and database operations
- **Views**: Present data to users through templates
- **Tables**: Handle database table operations
- **Schema**: Define mapping between different CMS formats and CCM
- **Services**: Provide shared functionality across the component

### Migration Process Flow

1. **Content Extraction**: Retrieves content from source CMS via API
2. **CCM Conversion**: Transforms content to CCM standard format
3. **Target Conversion**: Adapts CCM format to target CMS structure
4. **Import**: Creates content in target CMS

### Testing Strategy

- **Unit Tests**: Test individual classes and methods in isolation
- **E2E Tests**: Test complete user workflows through the browser
- **Comprehensive Coverage**: Both test suites provide full coverage of component functionality
