import Spinner from './Spinner';

const VARIANTS = {
    primary: 'bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 disabled:from-blue-800 disabled:to-blue-700 text-white shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40',
    secondary: 'bg-gray-800 hover:bg-gray-700 text-gray-300 border border-gray-700 hover:border-gray-600',
    danger: 'bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 hover:border-red-500/50'
};

export default function Button({
    children,
    variant = 'primary',
    loading = false,
    disabled = false,
    onClick,
    className = '',
    type = 'button',
    'aria-label': ariaLabel
}) {
    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled || loading}
            aria-label={ariaLabel}
            aria-busy={loading}
            aria-disabled={disabled || loading}
            className={`
                px-5 py-2.5 rounded-lg font-medium text-sm
                transition-all duration-200 ease-out
                flex items-center justify-center gap-2
                disabled:cursor-not-allowed disabled:opacity-60
                focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-900
                active:scale-[0.98]
                ${VARIANTS[variant]}
                ${className}
            `}
        >
            {loading && <Spinner aria-hidden="true" />}
            {children}
        </button>
    );
}
