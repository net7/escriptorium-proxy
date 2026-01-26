import { forwardRef } from 'react';

const Input = forwardRef(function Input({
    type = 'text',
    value,
    onChange,
    placeholder,
    className = '',
    id,
    'aria-label': ariaLabel,
    'aria-describedby': ariaDescribedBy,
    required = false,
    disabled = false,
    ...props
}, ref) {
    return (
        <input
            ref={ref}
            id={id}
            type={type}
            value={value}
            onChange={e => onChange(e.target.value)}
            placeholder={placeholder}
            required={required}
            disabled={disabled}
            aria-label={ariaLabel}
            aria-describedby={ariaDescribedBy}
            aria-required={required}
            className={`
                w-full px-4 py-2.5
                bg-gray-800/80 backdrop-blur-sm
                border border-gray-700 hover:border-gray-600
                rounded-lg
                text-gray-100 placeholder-gray-500
                text-sm font-mono
                transition-all duration-200
                focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500
                disabled:opacity-50 disabled:cursor-not-allowed
                ${className}
            `}
            {...props}
        />
    );
});

export default Input;
