# Content Migration Between CMSs Using CCM & MDE

**Google Summer of Code 2025 Project by Reem Atalah**

## 📋 Overview

The Content Creation Management (CCM) component is a Joomla extension that enables seamless content migration between different Content Management Systems (CMS) using a Common Content Model (CCM) and Model-Driven Engineering (MDE) format. This project facilitates migration between WordPress, Joomla and easily extending other CMS platforms.

##  Features

- **Multi-CMS Support**: Migrate content between WordPress, Joomla, and more.
- **Standardized Format**: Uses CCM schema for consistent content representation.
- **Metadata Preservation**: Maintains content metadata during migration.
- **User-Friendly Interface**: Intuitive Joomla administrator interface.
- **Comprehensive Testing**: Full test coverage with Cypress (E2E) and PHPUnit (Unit).
- **Simple Migration Feedback**: Displays a success message upon completion or a detailed failure message if migration fails.

## � Documentation

This project includes comprehensive documentation for different audiences:

### For Users
📝 **[User Guide](docs/USER_GUIDE.md)** - Step-by-step instructions for installing and using the CCM component to migrate content between CMSs.

### For Developers
🛠️ **[Developer Guide](docs/DEVELOPER_GUIDE.md)** - Technical documentation including project structure, development setup, testing procedures, and architectural details.

## 🚧 Future Work

- **User and Media Migration**: Implement full support for migrating users/authors and media files between CMSs.
- **Custom Field Mapping**: Enable advanced mapping for custom fields and metadata unique to each CMS.
- **API Discovery**: Automate detection and configuration of available APIs for supported CMSs to simplify setup and integration.
