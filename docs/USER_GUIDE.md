# User Guide - Content Migration Between CMSs Using CCM & MDE

## 📋 Overview

The Content Creation Management (CCM) component is a Joomla extension that enables seamless content migration between different Content Management Systems (CMS) using a Common Content Model (CCM) and Model-Driven Engineering (MDE) format. This project facilitates migration between WordPress, Joomla and easily extending other CMS platforms.

## 🚀 Features

- **Multi-CMS Support**: Migrate content between WordPress, Joomla, and more.
- **Standardized Format**: Uses CCM schema for consistent content representation.
- **Metadata Preservation**: Maintains content metadata during migration.
- **User-Friendly Interface**: Intuitive Joomla administrator interface.
- **Simple Migration Feedback**: Displays a success message upon completion or a detailed failure message if migration fails.

## 🛠️ Prerequisites

- **Joomla 4.0+** instance
- **PHP 8.1+** with MySQLi extension
- **MySQL/MariaDB** database

## 📦 Installation

### Joomla Installation

1. Open your Joomla app
2. Access the administrator panel
3. Navigate to **System > Install > Extensions**
4. Upload the zip file for the component

### Database Setup

The component will automatically create the required database tables upon installation:
- `#__ccm_cms` - Stores CMS configurations
- Initializes the CMSs available for migration

## 📝 How to Use the CCM Component

### 1. Editing a CMS

1. In Joomla Admin, go to **Components > CCM**
2. Choose one of the CMSs
3. Update the CMS details:
   - **Name**: Descriptive name for the CMS
   - **URL**: Base URL of the source CMS
   - **Credentials**: API keys or authentication details

### 2. Obtaining CMS Credentials

Before you can migrate content, you need to obtain the proper API credentials for each CMS:

#### For WordPress
1. **Install WordPress REST API Plugin** (if not already available):
   - Go to your WordPress admin panel
   - Navigate to **Plugins > Add New**
   - Search for "Application Passwords" or "REST API Authentication"
   - Install and activate the plugin

2. **Generate Application Password**:
   - Go to **Users > Profile** in WordPress admin
   - Scroll down to **Application Passwords** section
   - Enter a name for the application (e.g., "CCM Migration")
   - Click **Add New Application Password**
   - Copy the generated password (it won't be shown again)

#### For Joomla
1. **Create API Token**:
   - Go to **System > Manage > API Tokens**
   - Click **New** to create a new token
   - Enter a **Title** for the token
   - Select appropriate **User** (should have necessary permissions)
   - Click **Save**
   - Copy the generated token

> **Security Note**: Always use dedicated API credentials with minimal required permissions. Never use your main administrator password for API access.

### 3. Running a Migration

1. Navigate to **Components > CCM > Migration**
2. Select source and target CMS
3. Choose content types to migrate:
   - Categories
   - Media files *(future work)*
   - Users *(future work)*
   - Articles/Posts

> **Note:** It is important to migrate the referenced items first. For example, we need to migrate **categories first**, as articles/posts reference categories. Migrating articles before their referenced categories exist may result in missing or incorrect category assignments. Always follow this order: **Categories → Media files → Users → Articles/Posts**.

4. Click **Apply Migration**
5. Monitor progress in real-time

### 4. Migration Process

The migration follows these steps:

1. **Content Extraction**: Retrieves content from source CMS via API
2. **CCM Conversion**: Transforms content to CCM standard format
3. **Target Conversion**: Adapts CCM format to target CMS structure
4. **Import**: Creates content in target CMS
