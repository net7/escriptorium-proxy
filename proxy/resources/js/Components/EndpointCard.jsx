import { useState, useEffect, useCallback, useId } from 'react';
import EndpointHeader from './endpoint/EndpointHeader';
import FormField from './endpoint/FormField';
import PollingLogs from './endpoint/PollingLogs';
import JsonResult from './JsonResult';
import Button from './ui/Button';
import Spinner from './ui/Spinner';
import useApiRequest from '../hooks/useApiRequest';
import usePolling from '../hooks/usePolling';

export default function EndpointCard({
    endpoint,
    apiKey,
    scripts = [],
    recognitionModels = [],
    segmentationModels = [],
    onRefreshModels,
    loadingModels = false,
    onTranscriptionCreated,
    autoFillTranscriptionId,
    shouldStartPolling,
    onPollingStarted
}) {
    const id = useId();
    const [formData, setFormData] = useState({});
    const [files, setFiles] = useState({});

    const { loading, response, execute, reset: resetRequest, setResponse } = useApiRequest(apiKey);
    const polling = usePolling(apiKey);

    const isStatusEndpoint = endpoint.path === '/api/v1/process/{id}';

    // Set default values
    useEffect(() => {
        const defaults = {};

        if (scripts.length > 0 && !formData.script_id) {
            const latinScript = scripts.find(s => s.name === 'Latin');
            if (latinScript) defaults.script_id = latinScript.id;
        }

        if (endpoint.fields?.some(f => f.name === 'text_direction') && !formData.text_direction) {
            defaults.text_direction = 'horizontal-lr';
        }

        if (Object.keys(defaults).length > 0) {
            setFormData(prev => ({ ...prev, ...defaults }));
        }
    }, [scripts, endpoint.fields]);

    // Auto-fill transcription ID
    useEffect(() => {
        if (autoFillTranscriptionId && isStatusEndpoint) {
            setFormData(prev => ({ ...prev, id: autoFillTranscriptionId }));
        }
    }, [autoFillTranscriptionId, isStatusEndpoint]);

    // Auto-start polling
    useEffect(() => {
        if (shouldStartPolling && autoFillTranscriptionId && isStatusEndpoint) {
            onPollingStarted?.();
            polling.start(autoFillTranscriptionId, setResponse);
        }
    }, [shouldStartPolling, autoFillTranscriptionId, isStatusEndpoint]);

    const handleFieldChange = useCallback((name, value) => {
        setFormData(prev => ({ ...prev, [name]: value }));
    }, []);

    const handleFileChange = useCallback((name, fileList) => {
        setFiles(prev => ({ ...prev, [name]: fileList }));
    }, []);

    const buildUrl = useCallback(() => {
        let url = endpoint.path;
        endpoint.fields?.forEach(field => {
            if (field.pathParam && formData[field.name]) {
                url = url.replace(`{${field.name}}`, formData[field.name]);
            }
        });
        return url;
    }, [endpoint, formData]);

    const handleSubmit = async () => {
        polling.reset();

        const result = await execute({
            method: endpoint.method,
            url: buildUrl(),
            fields: endpoint.fields,
            formData,
            files
        });

        // Notify parent if transcription created
        if (onTranscriptionCreated && result?.status >= 200 && result?.status < 300 && result?.data?.transcription_id) {
            onTranscriptionCreated(result.data.transcription_id);
        }
    };

    const handleReset = () => {
        setFormData({});
        setFiles({});
        resetRequest();
        polling.reset();
    };

    const currentStatus = response?.data?.status;

    return (
        <article
            className="bg-gradient-to-br from-gray-900 to-gray-900/60 border border-gray-800 rounded-2xl overflow-hidden shadow-xl shadow-black/10 hover:shadow-black/20 transition-shadow duration-300"
            aria-labelledby={`${id}-title`}
        >
            <EndpointHeader
                method={endpoint.method}
                path={endpoint.path}
                contentType={endpoint.contentType}
                status={isStatusEndpoint ? currentStatus : null}
            />

            <div className="p-6">
                {/* Description */}
                <p id={`${id}-title`} className="text-gray-400 text-sm mb-6 leading-relaxed">
                    {endpoint.description}
                </p>

                {/* Form Fields */}
                {endpoint.fields?.length > 0 && (
                    <fieldset className="space-y-4 mb-6">
                        <legend className="sr-only">Request parameters for {endpoint.path}</legend>
                        {endpoint.fields.map(field => (
                            <FormField
                                key={field.name}
                                field={field}
                                value={formData[field.name]}
                                onChange={value => handleFieldChange(field.name, value)}
                                onFileChange={fileList => handleFileChange(field.name, fileList)}
                                scripts={scripts}
                                recognitionModels={recognitionModels}
                                segmentationModels={segmentationModels}
                                onRefreshModels={onRefreshModels}
                                loadingModels={loadingModels}
                            />
                        ))}
                    </fieldset>
                )}

                {/* Actions */}
                <div className="flex items-center gap-3 flex-wrap">
                    <Button
                        onClick={handleSubmit}
                        loading={loading}
                        disabled={polling.isPolling}
                        aria-label={`Send ${endpoint.method} request to ${endpoint.path}`}
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Send Request
                    </Button>

                    {isStatusEndpoint && polling.isPolling && (
                        <>
                            <Button variant="danger" onClick={polling.stop} aria-label="Stop polling">
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                                </svg>
                                Stop Polling
                            </Button>
                            <div
                                className="flex items-center gap-2 px-3 py-2 bg-blue-500/10 border border-blue-500/20 rounded-lg"
                                role="status"
                                aria-live="polite"
                            >
                                <Spinner className="h-4 w-4 text-blue-400" aria-label="Polling in progress" />
                                <span className="text-sm text-blue-400 font-medium">
                                    Polling #{polling.attempt}
                                </span>
                            </div>
                        </>
                    )}

                    {response && (
                        <Button variant="secondary" onClick={handleReset} aria-label="Reset form and response">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            Reset
                        </Button>
                    )}
                </div>

                {/* Response */}
                {response && (
                    <div className="mt-6 pt-6 border-t border-gray-800/80">
                        <h3 className="text-sm font-semibold text-gray-300 mb-4 flex items-center gap-2">
                            <svg className="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Response
                        </h3>
                        <JsonResult
                            data={response.data}
                            status={response.status}
                            headers={response.headers}
                        />
                    </div>
                )}

                {/* Polling Logs */}
                {isStatusEndpoint && <PollingLogs logs={polling.logs} />}
            </div>
        </article>
    );
}
