# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Backend (Django)
- **Start development server**: `cd app && python manage.py runserver`
- **Run tests**: `cd app && python manage.py test`
- **Run migrations**: `cd app && python manage.py migrate`
- **Create migrations**: `cd app && python manage.py makemigrations`
- **Create superuser**: `cd app && python manage.py createsuperuser`
- **Django shell**: `cd app && python manage.py shell`
- **Collect static files**: `cd app && python manage.py collectstatic`

### Frontend (JavaScript/Vue.js)
- **Build for development**: `cd front && npm run build`
- **Watch for changes**: `cd front && npm run start`
- **Build for production**: `cd front && npm run production`
- **Run Storybook**: `cd front && npm run storybook`

### Docker Development
- **Start all services**: `docker-compose up -d`
- **View logs**: `docker-compose logs -f [service_name]`
- **Run Django commands**: `docker-compose exec web python manage.py [command]`
- **Access Django shell**: `docker-compose exec web python manage.py shell`

### Testing
- **Run Django tests**: `cd app && python manage.py test --settings=escriptorium.test_settings`
- **Run specific test**: `cd app && python manage.py test apps.core.tests.test_models --settings=escriptorium.test_settings`

## Architecture Overview

eScriptorium is a Django-based web application for transcribing and annotating historical documents using OCR (Optical Character Recognition) technology.

### Core Components

**Backend Stack:**
- **Django 4.2+**: Main web framework
- **PostgreSQL**: Primary database
- **Redis**: Cache and Celery broker
- **Celery**: Asynchronous task processing
- **Elasticsearch**: Search indexing
- **Kraken**: OCR engine for text recognition
- **Channels/Daphne**: WebSocket support

**Frontend Stack:**
- **Vue.js 2.7**: Frontend framework
- **Webpack**: Module bundler
- **Bootstrap 4**: UI framework
- **jQuery**: DOM manipulation
- **Vuex**: State management

### Django Apps Structure

**core/**: Main application logic
- Document and project management
- OCR models and training
- Transcription and annotation handling
- User interface views and templates

**users/**: User management and authentication
- Custom User model extending AbstractUser
- Group management and permissions
- User profiles and quotas

**imports/**: Document import functionality
- Support for various formats (ALTO, PageXML, METS, PDF)
- Import parsers and validators
- Batch import processing

**api/**: REST API endpoints
- DRF-based API with nested routers
- Authentication via tokens
- CRUD operations for all major entities

**reporting/**: Task monitoring and reporting
- Celery task tracking
- Performance metrics
- User quota monitoring

**versioning/**: Document versioning system
- Track changes to transcriptions
- Version comparison and rollback

**bootstrap/**: UI components and templates
- Custom form widgets
- Template tags and filters

### Key Models

**Document**: Core entity representing a historical document
- Contains DocumentParts (individual images/pages)
- Links to Project for organization
- Supports multiple transcription layers

**Project**: Container for related documents
- User collaboration and sharing
- Project-specific settings and permissions

**OcrModel**: Machine learning models for text recognition
- Kraken-based OCR models
- Training data and validation metrics

**Transcription**: Text layer for document parts
- Multiple transcription types (OCR, manual, diplomatic)
- Line-level and block-level annotations

**Line/Block**: Structural elements of document layout
- Geometric coordinates and baselines
- Text content and confidence scores

### Asynchronous Processing

Celery queues handle computationally expensive tasks:
- **default**: General background tasks
- **live**: Real-time user interactions
- **low-priority**: Batch processing
- **gpu**: GPU-intensive OCR operations

### Frontend Architecture

**Vue Components**: Organized by feature with CSS co-location
- Document editing interface
- Project management dashboards
- Model training workflows

**State Management**: Vuex stores for:
- Document state and parts
- User interface state
- Image annotations and transcriptions

**API Integration**: Axios-based HTTP client with:
- Automatic CSRF token handling
- Request/response interceptors
- Error handling and retries

## Configuration

### Environment Variables
- `DEBUG`: Enable Django debug mode
- `SECRET_KEY`: Django secret key
- `DATABASE_URL`: PostgreSQL connection string
- `REDIS_URL`: Redis connection string
- `CELERY_BROKER_URL`: Celery broker configuration

### Local Development Setup
1. Copy `app/escriptorium/local_settings.py.example` to `app/escriptorium/local_settings.py`
2. Configure database settings in local_settings.py
3. Install Python dependencies: `pip install -r app/requirements.txt`
4. Install Node.js dependencies: `cd front && npm install`
5. Run migrations: `python manage.py migrate`
6. Build frontend: `cd front && npm run build`

### Docker Setup
1. Copy `docker-compose.override.yml_example` to `docker-compose.override.yml`
2. Create `variables.env` with required environment variables
3. Run: `docker-compose up -d`

## Testing Guidelines

### Django Tests
- Tests located in `apps/*/tests/` directories
- Use `test_settings.py` for test configuration
- Factory classes in `tests/factory.py` for test data
- Mock external dependencies (Kraken, Elasticsearch)

### Frontend Tests
- Storybook stories in `front/src/stories/`
- Component testing with Vue Test Utils
- Integration tests for API interactions

## Key File Locations

- **Settings**: `app/escriptorium/settings.py`
- **URL Configuration**: `app/escriptorium/urls.py`
- **API Routes**: `app/apps/api/urls.py`
- **Celery Configuration**: `app/escriptorium/celery.py`
- **Frontend Entry**: `front/src/main.js`
- **Webpack Config**: `front/webpack.*.js`
- **Templates**: `app/escriptorium/templates/`
- **Static Files**: `app/escriptorium/static/`

## Development Workflow

1. **Database Changes**: Create migrations after model changes
2. **Frontend Changes**: Run `npm run start` for hot reloading
3. **API Changes**: Update serializers and test endpoints
4. **Testing**: Run tests before committing changes
5. **Static Files**: Collect static files for production deployment

## Common Development Patterns

### Adding New Models
1. Define model in appropriate app's `models.py`
2. Create and run migrations
3. Add to Django admin if needed
4. Create API serializers and viewsets
5. Add frontend components for CRUD operations

### Implementing Async Tasks
1. Define task in `tasks.py`
2. Register appropriate Celery queue
3. Add task monitoring in reporting app
4. Handle task status in frontend

### Creating New Components
1. Follow existing Vue.js patterns in `front/vue/components/`
2. Use CSS modules for styling
3. Add to Storybook for documentation
4. Implement proper error handling