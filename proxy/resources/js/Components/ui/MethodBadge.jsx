const METHOD_CONFIG = {
    GET: {
        bg: 'bg-emerald-500/20',
        text: 'text-emerald-400',
        border: 'border-emerald-500/30',
        glow: 'shadow-emerald-500/20'
    },
    POST: {
        bg: 'bg-blue-500/20',
        text: 'text-blue-400',
        border: 'border-blue-500/30',
        glow: 'shadow-blue-500/20'
    },
    PUT: {
        bg: 'bg-amber-500/20',
        text: 'text-amber-400',
        border: 'border-amber-500/30',
        glow: 'shadow-amber-500/20'
    },
    PATCH: {
        bg: 'bg-orange-500/20',
        text: 'text-orange-400',
        border: 'border-orange-500/30',
        glow: 'shadow-orange-500/20'
    },
    DELETE: {
        bg: 'bg-red-500/20',
        text: 'text-red-400',
        border: 'border-red-500/30',
        glow: 'shadow-red-500/20'
    }
};

const DEFAULT_CONFIG = {
    bg: 'bg-gray-500/20',
    text: 'text-gray-400',
    border: 'border-gray-500/30',
    glow: 'shadow-gray-500/20'
};

export default function MethodBadge({ method }) {
    const config = METHOD_CONFIG[method] || DEFAULT_CONFIG;

    return (
        <span
            className={`
                inline-flex items-center justify-center
                min-w-[4rem] px-3 py-1.5
                ${config.bg} ${config.text}
                border ${config.border}
                rounded-md
                text-xs font-bold uppercase tracking-wider
                shadow-sm ${config.glow}
                transition-all duration-200
            `}
            aria-label={`HTTP method: ${method}`}
        >
            {method}
        </span>
    );
}
