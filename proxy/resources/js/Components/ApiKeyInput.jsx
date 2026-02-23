import { useState, useEffect, useId } from 'react';

const STORAGE_KEY = 'laravel_proxy_api_key';

export default function ApiKeyInput({ value, onChange }) {
    const id = useId();
    const [showKey, setShowKey] = useState(false);

    // Load from localStorage on mount
    useEffect(() => {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved && !value) {
            onChange(saved);
        }
    }, []);

    // Save to localStorage when value changes
    useEffect(() => {
        if (value) {
            localStorage.setItem(STORAGE_KEY, value);
        }
    }, [value]);

    const handleClear = () => {
        localStorage.removeItem(STORAGE_KEY);
        onChange('');
    };

    const isEskKey = value?.startsWith('esk_');
    const keyType = value ? (isEskKey ? 'Service Key' : 'eScriptorium Token') : null;

    return (
        <section
            className="bg-gradient-to-br from-gray-900 to-gray-900/80 border border-gray-800 rounded-2xl p-6 shadow-xl shadow-black/20"
            aria-labelledby={`${id}-title`}
        >
            <div className="flex items-center gap-3 mb-4">
                <div className="p-2 bg-blue-500/10 rounded-lg">
                    <svg
                        className="w-5 h-5 text-blue-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={1.5}
                            d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
                        />
                    </svg>
                </div>
                <h2 id={`${id}-title`} className="text-lg font-semibold text-white">
                    Authentication
                </h2>
            </div>

            <form onSubmit={e => e.preventDefault()} autoComplete="off">
                <div className="flex items-center gap-4">
                    <label htmlFor={`${id}-input`} className="sr-only">
                        API Key
                    </label>
                    <div className="flex-1 relative">
                        <input
                            id={`${id}-input`}
                            type={showKey ? 'text' : 'password'}
                            value={value}
                            onChange={(e) => onChange(e.target.value)}
                            placeholder="Enter API key (esk_... or eScriptorium token)"
                            aria-describedby={`${id}-hint`}
                            className="
                                w-full px-4 py-3 pr-20
                                bg-gray-800/60 backdrop-blur-sm
                                border border-gray-700 hover:border-gray-600
                                rounded-xl
                                text-gray-100 placeholder-gray-500
                                font-mono text-sm
                                transition-all duration-200
                                focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500
                            "
                            autoComplete="off"
                        />
                        <button
                            type="button"
                            onClick={() => setShowKey(!showKey)}
                            aria-label={showKey ? 'Hide API key' : 'Show API key'}
                            className="
                                absolute right-3 top-1/2 -translate-y-1/2
                                px-2.5 py-1
                                text-gray-500 hover:text-gray-300
                                text-xs font-medium
                                transition-colors
                                rounded-md hover:bg-gray-700/50
                            "
                        >
                            {showKey ? 'HIDE' : 'SHOW'}
                        </button>
                    </div>

                    <button
                        type="button"
                        onClick={handleClear}
                        disabled={!value}
                        aria-label="Clear API key"
                        className="
                            px-5 py-3
                            bg-gray-800 hover:bg-gray-700
                            disabled:opacity-40 disabled:cursor-not-allowed
                            text-gray-300 rounded-xl
                            text-sm font-medium
                            transition-all duration-200
                            border border-gray-700 hover:border-gray-600
                            focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-900
                        "
                    >
                        Clear
                    </button>

                    <div
                        className="flex items-center gap-2.5 min-w-[160px] px-4 py-2.5 bg-gray-800/40 rounded-xl border border-gray-700/50"
                        role="status"
                        aria-live="polite"
                    >
                        {value ? (
                            <>
                                <span className="relative flex h-2.5 w-2.5">
                                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                    <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                                </span>
                                <span className="text-sm text-emerald-400 font-medium">{keyType}</span>
                            </>
                        ) : (
                            <>
                                <span className="w-2.5 h-2.5 bg-gray-600 rounded-full"></span>
                                <span className="text-sm text-gray-500">No key set</span>
                            </>
                        )}
                    </div>
                </div>

                <p id={`${id}-hint`} className="mt-3 text-xs text-gray-500">
                    Use <code className="px-1.5 py-0.5 bg-gray-800 rounded text-gray-400">esk_*</code> for service mode or your eScriptorium token for direct mode.
                </p>
            </form>
        </section>
    );
}
