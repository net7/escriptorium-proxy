# eScriptorium Application Flow Documentation

## Overview

eScriptorium is a comprehensive Django-based web application for OCR (Optical Character Recognition) and HTR (Handwritten Text Recognition) processing of historical documents. The application provides a complete workflow from document upload to final transcription export, with collaborative features and machine learning model training capabilities.

## 1. User Authentication and Onboarding Flow

### 1.1 User Registration Process
- **Invitation-based registration**: Users cannot self-register; they must be invited by existing users with `users.can_invite` permission
- **Custom User model**: Extends Django's `AbstractUser` with additional fields for research, quotas, and legacy mode preferences
- **Email-based authentication**: Users login with email instead of username

### 1.2 Registration Workflow
1. **Invitation Creation**: Authorized user creates invitation via `/invite/` endpoint
2. **Email Delivery**: System sends invitation email with registration token
3. **Registration**: Recipient clicks link and completes registration form at `/accept/{token}/`
4. **Account Activation**: User account is created and activated automatically
5. **Group Assignment**: User is automatically added to invited group (if applicable)

### 1.3 Team/Group Management
- **Group Creation**: Users can create teams for collaboration
- **Group Invitations**: Two types - service invitations (new users) and group invitations (existing users)
- **Ownership Transfer**: Group owners can transfer ownership to other members
- **User Removal**: Group owners can remove members from teams

### 1.4 API Authentication
- **Token-based authentication**: Uses Django REST Framework tokens
- **Token regeneration**: Users can regenerate API tokens via profile
- **Session + Token**: Supports both session and token authentication

## 2. Project and Document Creation Workflow

### 2.1 Project Management
- **Project Creation**: Users create projects at `/projects/create/` with name and optional guidelines
- **Auto-slug generation**: System automatically generates unique slugs from project names
- **Project Sharing**: Projects can be shared with individual users or groups
- **Project Organization**: Projects serve as containers for related documents

### 2.2 Document Creation Process
1. **Document Creation**: Within project context at `/project/{slug}/document/create/`
2. **Metadata Setup**: Users can add key-value metadata pairs during creation
3. **Script Configuration**: Set main script and text direction (horizontal/vertical, LTR/RTL)
4. **Default Settings**: System creates default transcription layer and valid block/line types
5. **Workflow States**: Documents progress through draft → published → archived states

### 2.3 Document Import Process
- **Multi-format Support**: ALTO XML, PAGE XML, METS, PDF, ZIP archives, IIIF manifests
- **Validation**: System validates file formats and structure
- **Parsing**: Specialized parsers process different formats
- **Image Processing**: Images converted to PNG, thumbnails generated
- **Resumable Imports**: Support for interrupted import recovery

### 2.4 Document Structure
```
Project
├── Documents (1:N)
│   ├── DocumentParts/Pages (1:N)
│   │   ├── Blocks/Regions (1:N)
│   │   │   └── Lines (1:N)
│   │   │       └── LineTranscriptions (1:N)
│   │   └── Transcriptions (1:N)
│   └── Metadata (1:N)
└── Tags (1:N)
```

## 3. OCR Processing and Transcription Flow

### 3.1 OCR Pipeline Overview
The OCR processing follows a multi-stage pipeline:
1. **Image Conversion** → **Segmentation** → **Transcription** → **Post-processing**

### 3.2 Image Processing
- **Format Conversion**: Convert uploaded images to PNG format
- **Lossless Compression**: Optimize file sizes without quality loss
- **Thumbnail Generation**: Create thumbnails for UI display
- **Workflow States**: Images progress through: created → converting → converted

### 3.3 Segmentation Process
- **Layout Analysis**: Uses Kraken's `blla.segment()` for layout analysis
- **Line Detection**: Identifies text lines with baselines and masks
- **Region Detection**: Identifies text blocks and regions
- **Geometric Processing**: Stores polygon coordinates for lines and blocks
- **Reading Order**: Automatic line ordering based on text direction

### 3.4 OCR Model Management
- **Model Types**: Segmentation models (layout analysis) and Recognition models (text recognition)
- **Model Training**: Custom training using ground truth data
- **Model Sharing**: Permission system for model access control
- **Model Versioning**: Full version history with rollback capability
- **Model Formats**: Uses Kraken's VGSL format with `.mlmodel` files

### 3.5 Transcription Process
- **Kraken Integration**: Uses `rpred.rpred()` for character recognition
- **Confidence Scoring**: Character-level and line-level confidence scores
- **Multi-script Support**: Handles different writing systems and directions
- **BiDi Support**: Bidirectional text handling for mixed scripts

### 3.6 Asynchronous Processing
- **Celery Integration**: Uses Celery for heavy computational tasks
- **Queue Management**: Different queues for different task types (default, live, low-priority, gpu)
- **Progress Tracking**: Real-time progress updates via WebSocket
- **Error Handling**: Comprehensive error handling and retry mechanisms

## 4. Collaboration and Sharing Mechanisms

### 4.1 Permission System
- **Owner Permissions**: Full control over owned projects/documents
- **Shared Permissions**: Read/write access for shared projects/documents
- **Group Permissions**: Team-based access control
- **Model Permissions**: Separate sharing system for OCR models

### 4.2 Sharing Mechanisms
**Project Sharing:**
- **Individual Sharing**: Share projects with specific users via `shared_with_users` field
- **Group Sharing**: Share projects with entire teams via `shared_with_groups` field
- **Inherited Access**: Document access can be inherited from project sharing

**Document Sharing:**
- **Independent Sharing**: Documents can be shared independently of projects
- **Granular Control**: More specific than project-level sharing
- **User/Group Support**: Same sharing model as projects

### 4.3 Real-time Collaboration
- **WebSocket Integration**: Real-time updates via Django Channels
- **Live Updates**: Progress tracking for OCR processing
- **Collaborative Editing**: Multiple users can work on same document
- **Event System**: Uses `send_event()` for real-time notifications

### 4.4 Access Control Queries
The system uses complex Django queries to determine access:
```python
# Project read access
Q(owner=user) | Q(shared_with_users=user) | Q(shared_with_groups__user=user)

# Document access (includes project inheritance)
Q(owner=user) | Q(project__owner=user) | Q(project__shared_with_users=user) | 
Q(project__shared_with_groups__user=user) | Q(shared_with_users=user) | 
Q(shared_with_groups__user=user)
```

## 5. Complete Application Flow

### 5.1 User Journey
1. **Invitation** → **Registration** → **Profile Setup** → **Team Joining**
2. **Project Creation** → **Document Upload** → **Import Processing**
3. **OCR Processing** → **Manual Correction** → **Export**

### 5.2 Technical Flow
1. **Authentication**: User login/token authentication
2. **Project Management**: Create/organize projects
3. **Document Import**: Upload and parse documents
4. **Image Processing**: Convert and optimize images
5. **Segmentation**: Layout analysis and line detection
6. **OCR Processing**: Text recognition using trained models
7. **Manual Editing**: Collaborative transcription correction
8. **Export**: Multiple format export options

### 5.3 Data Flow
```
User Input → Image Upload → Format Conversion → Segmentation → 
OCR Processing → Transcription Storage → Manual Editing → 
Version Control → Export Generation
```

### 5.4 Key Integration Points
- **Kraken OCR Engine**: Core OCR processing
- **Celery/Redis**: Asynchronous task processing
- **PostgreSQL**: Primary data storage
- **Django Channels**: WebSocket communication
- **Vue.js Frontend**: Interactive user interface

### 5.5 Export Capabilities
- **Text Export**: Plain text format
- **PAGE XML**: Layout and transcription data
- **ALTO XML**: OCR-specific XML format
- **TEI XML**: Text Encoding Initiative format
- **OpenITI mARkdown**: Specialized markdown format

## 6. System Architecture

### 6.1 Backend Stack
- **Django 4.2+**: Main web framework
- **PostgreSQL**: Primary database
- **Redis**: Cache and Celery broker
- **Celery**: Asynchronous task processing
- **Elasticsearch**: Search indexing
- **Kraken**: OCR engine

### 6.2 Frontend Stack
- **Vue.js 2.7**: Frontend framework
- **Webpack**: Module bundler
- **Bootstrap 4**: UI framework
- **WebSocket**: Real-time communication

### 6.3 Key Features
- **Multi-format Import**: Supports various document formats
- **Custom Model Training**: Train OCR models on domain-specific data
- **Collaborative Editing**: Multiple users can work simultaneously
- **Version Control**: Track changes and enable rollback
- **Export Flexibility**: Multiple output formats
- **Resource Management**: CPU/GPU quota system
- **Real-time Updates**: WebSocket-based progress tracking

This documentation provides a comprehensive overview of the eScriptorium application flow, from user onboarding through document processing to final export, highlighting the collaborative and technical aspects of the system.