const STATUS_CONFIG = {
    PENDING: {
        bg: 'bg-gray-500/15',
        text: 'text-gray-400',
        border: 'border-gray-500/30',
        icon: '○'
    },
    IMPORTING: {
        bg: 'bg-blue-500/15',
        text: 'text-blue-400',
        border: 'border-blue-500/30',
        icon: '↓',
        pulse: true
    },
    SEGMENTING: {
        bg: 'bg-purple-500/15',
        text: 'text-purple-400',
        border: 'border-purple-500/30',
        icon: '◫',
        pulse: true
    },
    TRANSCRIBING: {
        bg: 'bg-indigo-500/15',
        text: 'text-indigo-400',
        border: 'border-indigo-500/30',
        icon: '✎',
        pulse: true
    },
    DOWNLOADING: {
        bg: 'bg-cyan-500/15',
        text: 'text-cyan-400',
        border: 'border-cyan-500/30',
        icon: '↧',
        pulse: true
    },
    PROCESSING: {
        bg: 'bg-teal-500/15',
        text: 'text-teal-400',
        border: 'border-teal-500/30',
        icon: '⚙',
        pulse: true
    },
    COMPLETED: {
        bg: 'bg-emerald-500/15',
        text: 'text-emerald-400',
        border: 'border-emerald-500/30',
        icon: '✓'
    },
    FAILED: {
        bg: 'bg-red-500/15',
        text: 'text-red-400',
        border: 'border-red-500/30',
        icon: '✕'
    }
};

const DEFAULT_CONFIG = {
    bg: 'bg-gray-500/15',
    text: 'text-gray-400',
    border: 'border-gray-500/30',
    icon: '?'
};

export default function StatusBadge({ status, showIcon = true, size = 'default' }) {
    if (!status) return null;

    const config = STATUS_CONFIG[status] || DEFAULT_CONFIG;

    const sizeClasses = {
        small: 'px-2 py-0.5 text-[10px]',
        default: 'px-2.5 py-1 text-xs',
        large: 'px-3 py-1.5 text-sm'
    };

    return (
        <span
            role="status"
            aria-label={`Status: ${status}`}
            className={`
                inline-flex items-center gap-1.5
                ${sizeClasses[size]}
                ${config.bg} ${config.text}
                border ${config.border}
                rounded-full
                font-semibold tracking-wide
                transition-all duration-300
                ${config.pulse ? 'animate-pulse' : ''}
            `}
        >
            {showIcon && (
                <span className="text-[0.9em]" aria-hidden="true">
                    {config.icon}
                </span>
            )}
            {status}
        </span>
    );
}

export { STATUS_CONFIG };
