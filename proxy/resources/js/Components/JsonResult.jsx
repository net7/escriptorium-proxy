import { useMemo } from 'react';

export default function JsonResult({ data, status, headers }) {
    const formattedJson = useMemo(() => {
        if (data === null || data === undefined) return null;
        try {
            return JSON.stringify(data, null, 2);
        } catch {
            return String(data);
        }
    }, [data]);

    const statusConfig = useMemo(() => {
        if (!status) return { color: 'text-gray-500', bg: 'bg-gray-800/50', label: 'Unknown', icon: '?' };
        if (status === 204) return { color: 'text-emerald-400', bg: 'bg-emerald-500/10', label: 'No Content', icon: '✓' };
        if (status >= 200 && status < 300) return { color: 'text-emerald-400', bg: 'bg-emerald-500/10', label: 'Success', icon: '✓' };
        if (status >= 400 && status < 500) return { color: 'text-amber-400', bg: 'bg-amber-500/10', label: 'Client Error', icon: '!' };
        if (status >= 500) return { color: 'text-red-400', bg: 'bg-red-500/10', label: 'Server Error', icon: '✕' };
        return { color: 'text-gray-500', bg: 'bg-gray-800/50', label: 'Unknown', icon: '?' };
    }, [status]);

    // No response yet
    if (status === undefined || status === null) {
        return (
            <div className="flex items-center gap-3 text-gray-500 italic text-sm py-4">
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                <span>No response yet. Send a request to see results.</span>
            </div>
        );
    }

    return (
        <div className="space-y-4" role="region" aria-label="API Response">
            {/* Status Badge */}
            <div className="flex items-center gap-3">
                <div
                    className={`
                        inline-flex items-center gap-2 px-4 py-2 rounded-xl
                        ${statusConfig.bg} border border-gray-700/50
                    `}
                >
                    <span className="text-gray-400 text-sm">Status</span>
                    <span className={`font-mono font-bold text-lg ${statusConfig.color}`}>
                        {status}
                    </span>
                </div>
                <span className={`text-sm font-medium ${statusConfig.color}`}>
                    {statusConfig.label}
                </span>
            </div>

            {/* Headers (collapsible) */}
            {headers && Object.keys(headers).length > 0 && (
                <details className="group">
                    <summary className="cursor-pointer text-xs text-gray-500 hover:text-gray-300 transition-colors flex items-center gap-2 select-none">
                        <svg
                            className="w-3.5 h-3.5 transition-transform group-open:rotate-90"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                        </svg>
                        <span>Response Headers</span>
                        <span className="text-gray-600">({Object.keys(headers).length})</span>
                    </summary>
                    <pre
                        className="mt-3 p-4 bg-gray-800/40 rounded-xl overflow-x-auto text-xs text-gray-400 border border-gray-800/80 font-mono"
                        aria-label="Response headers"
                    >
                        {JSON.stringify(headers, null, 2)}
                    </pre>
                </details>
            )}

            {/* 204 No Content - Show nice empty state */}
            {status === 204 || formattedJson === null ? (
                <div className={`
                    flex items-center justify-center gap-3 py-8
                    ${statusConfig.bg} rounded-xl border border-gray-800
                `}>
                    <div className={`
                        w-10 h-10 rounded-full flex items-center justify-center
                        ${status === 204 ? 'bg-emerald-500/20' : 'bg-gray-700/50'}
                    `}>
                        <svg
                            className={`w-5 h-5 ${status === 204 ? 'text-emerald-400' : 'text-gray-400'}`}
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            {status === 204 ? (
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                            ) : (
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
                            )}
                        </svg>
                    </div>
                    <div>
                        <p className={`font-medium ${status === 204 ? 'text-emerald-400' : 'text-gray-400'}`}>
                            {status === 204 ? 'No Content' : 'Empty Response'}
                        </p>
                        <p className="text-xs text-gray-500">
                            {status === 204
                                ? 'The server successfully processed the request'
                                : 'The response body is empty'
                            }
                        </p>
                    </div>
                </div>
            ) : (
                /* JSON Response */
                <div className="relative group">
                    <pre
                        className="
                            p-5 rounded-xl overflow-x-auto
                            bg-gray-950 border border-gray-800
                            text-sm text-gray-100 font-mono
                            leading-relaxed
                            max-h-[500px]
                            scrollbar-thin scrollbar-thumb-gray-700 scrollbar-track-transparent
                        "
                        aria-label="Response body"
                    >
                        <code>{formattedJson}</code>
                    </pre>

                    {/* Copy button */}
                    <button
                        onClick={() => navigator.clipboard.writeText(formattedJson)}
                        aria-label="Copy response to clipboard"
                        className="
                            absolute top-3 right-3
                            p-2 rounded-lg
                            bg-gray-800/80 hover:bg-gray-700
                            text-gray-400 hover:text-gray-200
                            opacity-0 group-hover:opacity-100
                            transition-all duration-200
                            focus:outline-none focus:opacity-100 focus-visible:ring-2 focus-visible:ring-blue-400
                        "
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </button>
                </div>
            )}
        </div>
    );
}
