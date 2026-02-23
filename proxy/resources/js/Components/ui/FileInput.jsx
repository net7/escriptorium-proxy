import { useState, useRef } from 'react';

export default function FileInput({
    onChange,
    multiple = false,
    accept,
    className = '',
    id,
    'aria-label': ariaLabel,
    'aria-describedby': ariaDescribedBy
}) {
    const [isDragging, setIsDragging] = useState(false);
    const [fileNames, setFileNames] = useState([]);
    const inputRef = useRef(null);

    const handleChange = (files) => {
        const names = Array.from(files).map(f => f.name);
        setFileNames(names);
        onChange(files);
    };

    const handleDragOver = (e) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = (e) => {
        e.preventDefault();
        setIsDragging(false);
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setIsDragging(false);
        if (e.dataTransfer.files?.length) {
            handleChange(e.dataTransfer.files);
        }
    };

    return (
        <div
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
            onClick={() => inputRef.current?.click()}
            onKeyDown={(e) => e.key === 'Enter' && inputRef.current?.click()}
            role="button"
            tabIndex={0}
            aria-label={ariaLabel || 'Upload files'}
            className={`
                relative group cursor-pointer
                w-full px-4 py-6
                bg-gray-800/50 hover:bg-gray-800/80
                border-2 border-dashed rounded-xl
                transition-all duration-200
                focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-900
                ${isDragging
                    ? 'border-blue-500 bg-blue-500/10'
                    : 'border-gray-700 hover:border-gray-600'
                }
                ${className}
            `}
        >
            <input
                ref={inputRef}
                id={id}
                type="file"
                multiple={multiple}
                accept={accept}
                onChange={e => handleChange(e.target.files)}
                aria-describedby={ariaDescribedBy}
                className="sr-only"
            />

            <div className="flex flex-col items-center gap-2 text-center">
                <div className={`
                    p-3 rounded-full
                    transition-colors duration-200
                    ${isDragging ? 'bg-blue-500/20' : 'bg-gray-700/50 group-hover:bg-gray-700'}
                `}>
                    <svg
                        className={`w-6 h-6 transition-colors ${isDragging ? 'text-blue-400' : 'text-gray-400'}`}
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={1.5}
                            d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"
                        />
                    </svg>
                </div>

                {fileNames.length > 0 ? (
                    <div className="space-y-1">
                        <p className="text-sm font-medium text-emerald-400">
                            {fileNames.length} file{fileNames.length > 1 ? 's' : ''} selected
                        </p>
                        <p className="text-xs text-gray-500 truncate max-w-[200px]">
                            {fileNames.join(', ')}
                        </p>
                    </div>
                ) : (
                    <div className="space-y-1">
                        <p className="text-sm text-gray-300">
                            <span className="font-medium text-blue-400">Click to upload</span>
                            {' '}or drag and drop
                        </p>
                        <p className="text-xs text-gray-500">
                            {accept ? accept.split(',').join(', ') : 'Any file type'}
                            {multiple && ' (multiple)'}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}
