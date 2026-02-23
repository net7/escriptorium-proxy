import { forwardRef } from 'react';

const Select = forwardRef(function Select({
    value,
    onChange,
    options = [],
    placeholder = 'Select...',
    emptyOption = true,
    getOptionValue = (opt) => opt.value ?? opt.id ?? opt,
    getOptionLabel = (opt) => opt.label ?? opt.name ?? opt,
    className = '',
    id,
    'aria-label': ariaLabel,
    'aria-describedby': ariaDescribedBy,
    required = false,
    disabled = false
}, ref) {
    return (
        <div className="relative">
            <select
                ref={ref}
                id={id}
                value={value || ''}
                onChange={e => onChange(e.target.value)}
                required={required}
                disabled={disabled}
                aria-label={ariaLabel}
                aria-describedby={ariaDescribedBy}
                aria-required={required}
                className={`
                    w-full px-4 py-2.5 pr-10
                    bg-gray-800/80 backdrop-blur-sm
                    border border-gray-700 hover:border-gray-600
                    rounded-lg
                    text-gray-100
                    text-sm
                    transition-all duration-200
                    focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500
                    disabled:opacity-50 disabled:cursor-not-allowed
                    appearance-none cursor-pointer
                    ${!value ? 'text-gray-500' : ''}
                    ${className}
                `}
            >
                {emptyOption && (
                    <option key="_empty" value="">{placeholder}</option>
                )}
                {options.map((opt, idx) => {
                    const optValue = getOptionValue(opt);
                    const optLabel = getOptionLabel(opt);
                    return (
                        <option key={`${optValue}_${idx}`} value={optValue}>
                            {optLabel}
                        </option>
                    );
                })}
            </select>
            <div className="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                <svg
                    className="w-4 h-4 text-gray-500"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
            </div>
        </div>
    );
});

export default Select;
