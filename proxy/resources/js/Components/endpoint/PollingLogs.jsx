import StatusBadge from '../ui/StatusBadge';

export default function PollingLogs({ logs }) {
    if (logs.length <= 1) return null;

    return (
        <details className="mt-5 group">
            <summary className="cursor-pointer text-sm text-gray-500 hover:text-gray-300 transition-colors flex items-center gap-2 select-none">
                <svg
                    className="w-4 h-4 transition-transform group-open:rotate-90"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                </svg>
                <span>View all {logs.length} polling attempts</span>
            </summary>

            <div
                className="mt-4 max-h-72 overflow-y-auto space-y-2 pr-2 scrollbar-thin scrollbar-thumb-gray-700 scrollbar-track-transparent"
                role="log"
                aria-label="Polling history"
            >
                {logs.map((log, i) => (
                    <article
                        key={i}
                        className="p-4 bg-gray-800/40 rounded-xl text-xs border border-gray-800/80 hover:border-gray-700/80 transition-colors"
                    >
                        <header className="flex items-center gap-3 mb-3">
                            <span className="inline-flex items-center justify-center w-7 h-7 bg-gray-700/50 rounded-lg font-bold text-gray-300">
                                {log.attempt}
                            </span>

                            <time
                                className="text-gray-500"
                                dateTime={log.timestamp}
                            >
                                {new Date(log.timestamp).toLocaleTimeString()}
                            </time>

                            {log.status && (
                                <span
                                    className={`
                                        px-2 py-0.5 rounded-md text-[10px] font-bold
                                        ${log.status >= 200 && log.status < 300
                                            ? 'bg-emerald-500/15 text-emerald-400'
                                            : 'bg-red-500/15 text-red-400'
                                        }
                                    `}
                                >
                                    {log.status}
                                </span>
                            )}

                            {log.data?.status && (
                                <StatusBadge status={log.data.status} size="small" />
                            )}
                        </header>

                        <pre className="text-gray-400 overflow-x-auto font-mono text-[11px] leading-relaxed bg-gray-900/50 rounded-lg p-3">
                            {JSON.stringify(log.data || { error: log.error }, null, 2)}
                        </pre>
                    </article>
                ))}
            </div>
        </details>
    );
}
