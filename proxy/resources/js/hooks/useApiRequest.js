import { useState, useCallback } from 'react';

export default function useApiRequest(apiKey) {
    const [loading, setLoading] = useState(false);
    const [response, setResponse] = useState(null);

    const execute = useCallback(async ({ method, url, fields, formData, files }) => {
        if (!apiKey) {
            alert('Please enter an API key first');
            return null;
        }

        setLoading(true);
        setResponse(null);

        try {
            const headers = { 'X-API-Key': apiKey };
            let fetchOptions = { method, headers };

            if (method === 'GET') {
                // No body for GET
            } else {
                const hasFiles = fields?.some(f => f.type === 'file' && files?.[f.name]?.length);

                if (hasFiles) {
                    const formDataObj = new FormData();
                    fields?.forEach(field => {
                        if (field.type === 'file' && files?.[field.name]) {
                            Array.from(files[field.name]).forEach(file => {
                                formDataObj.append(`${field.name}[]`, file);
                            });
                        } else if (!field.pathParam && formData?.[field.name] !== undefined && formData?.[field.name] !== '') {
                            formDataObj.append(field.name, formData[field.name]);
                        }
                    });
                    fetchOptions.body = formDataObj;
                } else {
                    headers['Content-Type'] = 'application/json';
                    fetchOptions.headers = headers;

                    const bodyData = {};
                    fields?.forEach(field => {
                        if (!field.pathParam && formData?.[field.name] !== undefined && formData?.[field.name] !== '') {
                            if (field.type === 'number' || field.dataType === 'number') {
                                bodyData[field.name] = parseInt(formData[field.name], 10);
                            } else {
                                bodyData[field.name] = formData[field.name];
                            }
                        }
                    });
                    fetchOptions.body = JSON.stringify(bodyData);
                }
            }

            const res = await fetch(url, fetchOptions);

            const responseHeaders = {};
            res.headers.forEach((value, key) => {
                responseHeaders[key] = value;
            });

            let data;

            // Handle 204 No Content first
            if (res.status === 204) {
                data = null;
            } else {
                const contentType = res.headers.get('content-type') || '';

                if (contentType.includes('application/json')) {
                    const text = await res.text();
                    // Handle empty JSON responses
                    data = text ? JSON.parse(text) : null;
                } else if (
                    contentType.includes('application/zip') ||
                    contentType.includes('application/octet-stream') ||
                    contentType.includes('application/xml') ||
                    res.headers.get('content-disposition')?.includes('attachment')
                ) {
                    // Binary/download response - trigger browser download
                    const blob = await res.blob();
                    const disposition = res.headers.get('content-disposition') || '';
                    const filenameMatch = disposition.match(/filename="?([^";\n]+)"?/);
                    const filename = filenameMatch?.[1] || 'download';

                    const downloadUrl = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = downloadUrl;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(downloadUrl);

                    data = { message: `File downloaded: ${filename}`, size: `${(blob.size / 1024).toFixed(1)} KB` };
                } else {
                    const text = await res.text();
                    // If response body is empty, treat as null
                    data = text ? { text } : null;
                }
            }

            const result = { status: res.status, headers: responseHeaders, data };
            setResponse(result);
            return result;
        } catch (error) {
            const result = { status: 0, data: { error: error.message } };
            setResponse(result);
            return result;
        } finally {
            setLoading(false);
        }
    }, [apiKey]);

    const reset = useCallback(() => {
        setResponse(null);
    }, []);

    return { loading, response, execute, reset, setResponse };
}
