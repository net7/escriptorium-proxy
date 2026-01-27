import { useState, useEffect, useRef } from 'react';
import ApiKeyInput from '../Components/ApiKeyInput';
import EndpointCard from '../Components/EndpointCard';
import Spinner from '../Components/ui/Spinner';

export default function Debug({ endpoints }) {
    const [apiKey, setApiKey] = useState('');
    const [scripts, setScripts] = useState([]);
    const [models, setModels] = useState([]);
    const [loadingData, setLoadingData] = useState(false);
    const [loadingModels, setLoadingModels] = useState(false);

    // Shared state for auto-polling
    const [activeTranscriptionId, setActiveTranscriptionId] = useState(null);
    const [shouldStartPolling, setShouldStartPolling] = useState(false);

    // Ref to scroll to status endpoint
    const statusEndpointRef = useRef(null);

    // Function to refresh only models
    const refreshModels = async () => {
        if (!apiKey) return;

        setLoadingModels(true);
        try {
            const modelsRes = await fetch('/api/v1/models', { headers: { 'X-API-Key': apiKey } });
            if (modelsRes.ok) {
                const modelsData = await modelsRes.json();
                setModels(modelsData.results || modelsData || []);
            }
        } catch (error) {
            console.error('Failed to refresh models:', error);
        } finally {
            setLoadingModels(false);
        }
    };

    // Fetch scripts and models when API key is set
    useEffect(() => {
        if (!apiKey) {
            setScripts([]);
            setModels([]);
            return;
        }

        const fetchData = async () => {
            setLoadingData(true);
            try {
                const [scriptsRes, modelsRes] = await Promise.all([
                    fetch('/api/v1/scripts', { headers: { 'X-API-Key': apiKey } }),
                    fetch('/api/v1/models', { headers: { 'X-API-Key': apiKey } })
                ]);

                if (scriptsRes.ok) {
                    const scriptsData = await scriptsRes.json();
                    setScripts(scriptsData.results || scriptsData || []);
                }

                if (modelsRes.ok) {
                    const modelsData = await modelsRes.json();
                    setModels(modelsData.results || modelsData || []);
                }
            } catch (error) {
                console.error('Failed to fetch data:', error);
            } finally {
                setLoadingData(false);
            }
        };

        fetchData();
    }, [apiKey]);

    // Filter models by job type
    const recognitionModels = models.filter(m => m.job === 'recognize');
    const segmentationModels = models.filter(m => m.job === 'segment');

    // Handler when a process endpoint returns a transcription_id
    const handleTranscriptionCreated = (transcriptionId) => {
        setActiveTranscriptionId(transcriptionId);
        setShouldStartPolling(true);

        // Scroll to status endpoint
        setTimeout(() => {
            statusEndpointRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);
    };

    return (
        <div className="min-h-screen bg-gray-950">
            {/* Background gradient */}
            <div className="fixed inset-0 bg-gradient-to-br from-blue-950/20 via-transparent to-purple-950/20 pointer-events-none" aria-hidden="true" />

            {/* Header */}
            <header className="relative bg-gray-900/80 backdrop-blur-sm border-b border-gray-800 sticky top-0 z-10">
                <div className="max-w-6xl mx-auto px-6 py-5">
                    <div className="flex items-center gap-4">
                        <div className="p-2.5 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg shadow-blue-500/20">
                            <svg className="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                            </svg>
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold text-white tracking-tight">
                                Laravel Proxy
                            </h1>
                            <p className="text-gray-400 text-sm">
                                API Debug Console
                            </p>
                        </div>
                    </div>
                </div>
            </header>

            {/* Main content */}
            <main className="relative max-w-6xl mx-auto px-6 py-8">
                {/* API Key section */}
                <div className="mb-8">
                    <ApiKeyInput value={apiKey} onChange={setApiKey} />
                </div>

                {/* Loading indicator */}
                {loadingData && (
                    <div
                        className="mb-6 flex items-center gap-3 px-4 py-3 bg-blue-500/10 border border-blue-500/20 rounded-xl"
                        role="status"
                        aria-live="polite"
                    >
                        <Spinner className="h-5 w-5 text-blue-400" />
                        <span className="text-sm text-blue-300">Loading scripts and models...</span>
                    </div>
                )}

                {/* Endpoints */}
                <div className="space-y-6">
                    {endpoints.map((endpoint, index) => {
                        const isStatusEndpoint = endpoint.path === '/api/v1/process/{id}';

                        return (
                            <div
                                key={`${endpoint.method}-${endpoint.path}-${index}`}
                                ref={isStatusEndpoint ? statusEndpointRef : null}
                            >
                                <EndpointCard
                                    endpoint={endpoint}
                                    apiKey={apiKey}
                                    scripts={scripts}
                                    recognitionModels={recognitionModels}
                                    segmentationModels={segmentationModels}
                                    onRefreshModels={refreshModels}
                                    loadingModels={loadingModels}
                                    onTranscriptionCreated={endpoint.autoPolling ? handleTranscriptionCreated : undefined}
                                    autoFillTranscriptionId={isStatusEndpoint ? activeTranscriptionId : null}
                                    shouldStartPolling={isStatusEndpoint ? shouldStartPolling : false}
                                    onPollingStarted={() => setShouldStartPolling(false)}
                                />
                            </div>
                        );
                    })}
                </div>

                {/* Footer */}
                <footer className="mt-12 pt-8 border-t border-gray-800/50">
                    <div className="flex items-center justify-center gap-2 text-sm text-gray-500">
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <span>This page is only available in local environment (APP_ENV=local)</span>
                    </div>
                </footer>
            </main>
        </div>
    );
}
