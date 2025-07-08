# eScriptorium API - Document Processing with Models

## Overview

This document describes the API endpoints available in eScriptorium for processing documents using OCR/HTR models. All endpoints are asynchronous and use Celery for background processing.

## Authentication

All API calls require authentication. eScriptorium supports two authentication methods:

### Token Authentication (Recommended)
```bash
# Get token
curl -X POST https://your-domain.com/api/token-auth/ \
     -d "username=your_username&password=your_password"

# Use token in requests
curl -H "Authorization: Token YOUR_API_TOKEN" \
     -H "Content-Type: application/json" \
     https://your-domain.com/api/endpoint/
```

### Session Authentication
1. Login via `/api/api-auth/login/`
2. Use session cookies for subsequent requests

## Document Processing Endpoints

### 1. Document Segmentation (Layout Analysis)

**Endpoint:** `POST /api/documents/{document_id}/segment/`

**Purpose:** Analyze document layout to detect text regions and lines using Kraken's segmentation models.

**Request Parameters:**
```json
{
  "model": "model_id_or_file",           // Optional: segmentation model ID or file path
  "parts": [1, 2, 3],                   // Optional: specific document parts to process
  "override": "both|lines|regions|masks", // What to segment (default: "both")
  "text_direction": "horizontal-lr|horizontal-rl|vertical-lr|vertical-rl"  // Text direction
}
```

**Parameters Details:**
- `model`: Use a specific segmentation model. If not provided, uses default Kraken model
- `parts`: Array of DocumentPart IDs to process. If not provided, processes all parts
- `override`: 
  - `both`: Segment both lines and regions
  - `lines`: Only segment text lines
  - `regions`: Only segment text regions
  - `masks`: Only generate line masks
- `text_direction`: Document reading direction

**Response:**
```json
{
  "status": "ok"
}
```

**Example:**
```bash
curl -X POST https://your-domain.com/api/documents/123/segment/ \
     -H "Authorization: Token YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "override": "both",
       "text_direction": "horizontal-lr"
     }'
```

### 2. Document Transcription (OCR/HTR)

**Endpoint:** `POST /api/documents/{document_id}/transcribe/`

**Purpose:** Extract text from segmented document lines using OCR/HTR models.

**Request Parameters:**
```json
{
  "model": "model_id_or_file",     // Required: OCR/HTR model ID or file path
  "parts": [1, 2, 3],             // Optional: specific document parts
  "transcription": "transcription_id"  // Optional: target transcription layer
}
```

**Parameters Details:**
- `model`: ID of the OCR/HTR model to use for transcription
- `parts`: Array of DocumentPart IDs to transcribe. If not provided, transcribes all parts
- `transcription`: Target transcription layer ID. If not provided, uses default layer

**Response:**
```json
{
  "status": "ok"
}
```

**Example:**
```bash
curl -X POST https://your-domain.com/api/documents/123/transcribe/ \
     -H "Authorization: Token YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "model": "your_model_id",
       "transcription": "manual"
     }'
```

### 3. Recognition Model Training

**Endpoint:** `POST /api/documents/{document_id}/train/`

**Purpose:** Train a new OCR/HTR recognition model using ground truth transcription data.

**Request Parameters:**
```json
{
  "name": "My New Model",          // Required: name for the new model
  "transcription": "transcription_id",  // Required: transcription layer with ground truth
  "model": "base_model_id",        // Optional: base model to fine-tune
  "parts": [1, 2, 3],             // Optional: specific parts for training
  "script": "script_id"           // Optional: script/writing system
}
```

**Parameters Details:**
- `name`: Name for the new trained model
- `transcription`: Transcription layer containing ground truth data
- `model`: Base model for fine-tuning. If not provided, trains from scratch
- `parts`: Document parts to use for training. If not provided, uses all parts
- `script`: Script/writing system for the model

**Response:**
```json
{
  "status": "ok"
}
```

**Example:**
```bash
curl -X POST https://your-domain.com/api/documents/123/train/ \
     -H "Authorization: Token YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "name": "Medieval Latin Model",
       "transcription": "manual",
       "model": "base_latin_model",
       "script": "Latn"
     }'
```

### 4. Segmentation Model Training

**Endpoint:** `POST /api/documents/{document_id}/segtrain/`

**Purpose:** Train a new segmentation model using ground truth layout data.

**Request Parameters:**
```json
{
  "name": "My Segmentation Model",  // Required: name for the new model
  "model": "base_model_id",        // Optional: base segmentation model
  "parts": [1, 2, 3],             // Required: parts with ground truth segmentation
  "script": "script_id"           // Optional: script/writing system
}
```

**Parameters Details:**
- `name`: Name for the new segmentation model
- `model`: Base segmentation model for fine-tuning
- `parts`: Document parts with ground truth segmentation (regions and baselines)
- `script`: Script/writing system for the model

**Response:**
```json
{
  "status": "ok"
}
```

**Example:**
```bash
curl -X POST https://your-domain.com/api/documents/123/segtrain/ \
     -H "Authorization: Token YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "name": "Medieval Manuscript Segmentation",
       "parts": [1, 2, 3, 4, 5],
       "script": "Latn"
     }'
```

### 5. Text Alignment

**Endpoint:** `POST /api/documents/{document_id}/align/`

**Purpose:** Align OCR results with reference text using sequence alignment algorithms.

**Request Parameters:**
```json
{
  "transcription": "transcription_id",  // Required: transcription layer to align
  "witness": "witness_id",             // Required: reference text witness
  "parts": [1, 2, 3],                 // Optional: specific parts to align
  "n_gram": 10,                       // Optional: n-gram size for alignment
  "merge": false,                     // Optional: merge aligned results
  "region_types": ["region_type_id"]  // Optional: specific region types
}
```

**Parameters Details:**
- `transcription`: Transcription layer containing OCR results
- `witness`: TextualWitness ID containing reference text
- `parts`: Document parts to align. If not provided, aligns all parts
- `n_gram`: N-gram size for sequence alignment algorithm
- `merge`: Whether to merge alignment results into transcription
- `region_types`: Only align specific region types

**Response:**
```json
{
  "status": "ok"
}
```

**Example:**
```bash
curl -X POST https://your-domain.com/api/documents/123/align/ \
     -H "Authorization: Token YOUR_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{
       "transcription": "ocr_results",
       "witness": "reference_text_id",
       "n_gram": 15,
       "merge": true
     }'
```

## Model Management

### List Available Models

**Endpoint:** `GET /api/models/`

**Purpose:** Retrieve list of available OCR/HTR models.

**Response:**
```json
{
  "count": 25,
  "next": "http://example.com/api/models/?page=2",
  "previous": null,
  "results": [
    {
      "id": 1,
      "name": "Latin OCR Model",
      "file": "/path/to/model.mlmodel",
      "script": "Latn",
      "job": "recognize",
      "accuracy": 0.95,
      "created": "2023-01-01T00:00:00Z"
    }
  ]
}
```

**Example:**
```bash
curl -X GET https://your-domain.com/api/models/ \
     -H "Authorization: Token YOUR_TOKEN"
```

### Upload New Model

**Endpoint:** `POST /api/models/`

**Purpose:** Upload a new OCR/HTR model file.

**Request:** Multipart form data with model file

**Example:**
```bash
curl -X POST https://your-domain.com/api/models/ \
     -H "Authorization: Token YOUR_TOKEN" \
     -F "name=My Custom Model" \
     -F "file=@model.mlmodel" \
     -F "script=Latn" \
     -F "job=recognize"
```

## Document Import

### Import Document

**Endpoint:** `POST /api/documents/{document_id}/import/`

**Purpose:** Import document files in various formats (PDF, ALTO, PAGE XML, etc.).

**Request:** Multipart form data with document file

**Example:**
```bash
curl -X POST https://your-domain.com/api/documents/123/import/ \
     -H "Authorization: Token YOUR_TOKEN" \
     -F "import_file=@document.pdf"
```

## Processing Status and Monitoring

### Check Document Parts Status

**Endpoint:** `GET /api/documents/{document_id}/parts/`

**Purpose:** Check the processing status of document parts.

**Response:**
```json
{
  "results": [
    {
      "id": 1,
      "order": 1,
      "filename": "page_001.png",
      "workflow_state": "transcribing",
      "thumbnail": "/media/thumbnails/page_001_thumb.png"
    }
  ]
}
```

**Workflow States:**
- `created`: Initial state
- `converting`: Image format conversion
- `converted`: Ready for processing
- `segmenting`: Layout analysis in progress
- `segmented`: Segmentation complete
- `transcribing`: OCR/HTR in progress
- `transcribed`: Transcription complete

### Task Monitoring

**Endpoint:** `GET /api/documents/{document_id}/task_groups/`

**Purpose:** Monitor background task progress.

**Response:**
```json
{
  "results": [
    {
      "id": 1,
      "name": "Document Transcription",
      "progress": 75,
      "status": "running",
      "created": "2023-01-01T00:00:00Z"
    }
  ]
}
```

## Complete Processing Workflow Example

Here's a complete example of processing a document from upload to transcription:

```bash
# 1. Authenticate
TOKEN=$(curl -X POST https://your-domain.com/api/token-auth/ \
     -d "username=your_username&password=your_password" | jq -r '.token')

# 2. Create or get document ID
DOCUMENT_ID=123

# 3. Import document
curl -X POST https://your-domain.com/api/documents/${DOCUMENT_ID}/import/ \
     -H "Authorization: Token ${TOKEN}" \
     -F "import_file=@manuscript.pdf"

# 4. Wait for import to complete, then segment
curl -X POST https://your-domain.com/api/documents/${DOCUMENT_ID}/segment/ \
     -H "Authorization: Token ${TOKEN}" \
     -H "Content-Type: application/json" \
     -d '{"override": "both", "text_direction": "horizontal-lr"}'

# 5. Wait for segmentation to complete, then transcribe
curl -X POST https://your-domain.com/api/documents/${DOCUMENT_ID}/transcribe/ \
     -H "Authorization: Token ${TOKEN}" \
     -H "Content-Type: application/json" \
     -d '{"model": "latin_model_id"}'

# 6. Monitor progress
curl -X GET https://your-domain.com/api/documents/${DOCUMENT_ID}/parts/ \
     -H "Authorization: Token ${TOKEN}"

# 7. Check results
curl -X GET https://your-domain.com/api/documents/${DOCUMENT_ID}/transcriptions/ \
     -H "Authorization: Token ${TOKEN}"
```

## Error Handling

All endpoints return appropriate HTTP status codes:

- `200 OK`: Success
- `400 Bad Request`: Invalid parameters or processing error
- `401 Unauthorized`: Authentication required
- `403 Forbidden`: Insufficient permissions
- `404 Not Found`: Resource not found
- `500 Internal Server Error`: Server error

Error responses include details:
```json
{
  "status": "error",
  "error": "Model not found or not accessible"
}
```

## Rate Limiting and Quotas

eScriptorium implements user quotas for:
- CPU minutes (processing time)
- GPU minutes (for GPU-accelerated processing)
- Storage space (for uploaded documents)

Quota exceeded responses:
```json
{
  "error": "You don't have any CPU minutes left."
}
```

## WebSocket Support

Real-time progress updates are available via WebSocket connections. Connect to:
```
wss://your-domain.com/ws/documents/{document_id}/
```

Progress messages include:
```json
{
  "type": "task_progress",
  "task_id": "celery_task_id",
  "progress": 45,
  "message": "Processing page 3 of 10"
}
```

## Notes

1. All processing is asynchronous - API calls return immediately while processing happens in the background
2. Use WebSocket connections or polling for real-time progress updates
3. Models must be trained or uploaded before they can be used for processing
4. Segmentation must be completed before transcription can begin
5. Ground truth data is required for model training
6. Processing respects user quotas and permissions

For more information, see the complete application flow documentation and the Django admin interface for advanced model management.