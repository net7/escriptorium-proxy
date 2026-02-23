import { useId } from 'react';
import Input from '../ui/Input';
import Select from '../ui/Select';
import FileInput from '../ui/FileInput';

export default function FormField({
    field,
    value,
    onChange,
    onFileChange,
    scripts = [],
    recognitionModels = [],
    segmentationModels = [],
    onRefreshModels,
    loadingModels = false
}) {
    const id = useId();
    const { name, type, required, placeholder, options, multiple, accept, description } = field;

    const renderInput = () => {
        // Dynamic select for script_id
        if (name === 'script_id') {
            return (
                <Select
                    id={id}
                    value={value}
                    onChange={onChange}
                    options={scripts}
                    placeholder="Select script..."
                    getOptionValue={s => s.id}
                    getOptionLabel={s => s.name}
                    required={required}
                    aria-describedby={description ? `${id}-desc` : undefined}
                />
            );
        }

        // Dynamic select for recognition_model_id
        if (name === 'recognition_model_id') {
            return (
                <div className="flex gap-2">
                    <div className="flex-1">
                        <Select
                            id={id}
                            value={value}
                            onChange={onChange}
                            options={recognitionModels}
                            placeholder="Select recognition model..."
                            getOptionValue={m => m.id}
                            getOptionLabel={m => m.name}
                            required={required}
                            aria-describedby={description ? `${id}-desc` : undefined}
                        />
                    </div>
                    {onRefreshModels && (
                        <button
                            type="button"
                            onClick={onRefreshModels}
                            disabled={loadingModels}
                            className="px-3 py-2 bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-300 border border-gray-700 hover:border-gray-600 rounded-lg transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                            aria-label="Refresh models"
                            title="Refresh models list"
                        >
                            <svg
                                className={`w-4 h-4 ${loadingModels ? 'animate-spin' : ''}`}
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>
                    )}
                </div>
            );
        }

        // Dynamic select for segmentation_model_id
        if (name === 'segmentation_model_id') {
            return (
                <div className="flex gap-2">
                    <div className="flex-1">
                        <Select
                            id={id}
                            value={value}
                            onChange={onChange}
                            options={segmentationModels}
                            placeholder="Default (eScriptorium built-in)"
                            getOptionValue={m => m.id}
                            getOptionLabel={m => m.name}
                            required={required}
                            aria-describedby={description ? `${id}-desc` : undefined}
                        />
                    </div>
                    {onRefreshModels && (
                        <button
                            type="button"
                            onClick={onRefreshModels}
                            disabled={loadingModels}
                            className="px-3 py-2 bg-gray-800 hover:bg-gray-700 text-gray-400 hover:text-gray-300 border border-gray-700 hover:border-gray-600 rounded-lg transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                            aria-label="Refresh models"
                            title="Refresh models list"
                        >
                            <svg
                                className={`w-4 h-4 ${loadingModels ? 'animate-spin' : ''}`}
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>
                    )}
                </div>
            );
        }

        // Regular select
        if (type === 'select') {
            return (
                <Select
                    id={id}
                    value={value}
                    onChange={onChange}
                    options={options || []}
                    placeholder="Select..."
                    getOptionValue={o => o}
                    getOptionLabel={o => o}
                    required={required}
                    aria-describedby={description ? `${id}-desc` : undefined}
                />
            );
        }

        // File input
        if (type === 'file') {
            return (
                <FileInput
                    id={id}
                    onChange={onFileChange}
                    multiple={multiple}
                    accept={accept}
                    aria-label={`Upload ${name}`}
                    aria-describedby={description ? `${id}-desc` : undefined}
                />
            );
        }

        // Default text/number input
        return (
            <Input
                id={id}
                type={type || 'text'}
                value={value || ''}
                onChange={onChange}
                placeholder={placeholder}
                required={required}
                aria-describedby={description ? `${id}-desc` : undefined}
            />
        );
    };

    return (
        <div className="group">
            <div className="flex items-start gap-4">
                <label
                    htmlFor={id}
                    className="w-44 flex items-center justify-end gap-2 pt-2.5 text-right"
                >
                    <span className="text-sm font-medium text-gray-400 group-hover:text-gray-300 transition-colors">
                        {name}
                    </span>
                    {required && (
                        <span
                            className="text-red-400 text-xs"
                            title="Required field"
                            aria-label="Required"
                        >
                            *
                        </span>
                    )}
                </label>
                <div className="flex-1">
                    {renderInput()}
                    {description && (
                        <p
                            id={`${id}-desc`}
                            className="mt-1.5 text-xs text-gray-500"
                        >
                            {description}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
