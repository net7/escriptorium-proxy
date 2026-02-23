import MethodBadge from '../ui/MethodBadge';
import StatusBadge from '../ui/StatusBadge';

export default function EndpointHeader({ method, path, contentType, status }) {
    return (
        <header className="flex items-center gap-4 px-5 py-4 bg-gradient-to-r from-gray-900/80 to-gray-900/40 border-b border-gray-800/80">
            <MethodBadge method={method} />

            <code className="font-mono text-sm text-gray-200 tracking-wide">
                {path}
            </code>

            <div className="flex items-center gap-3 ml-auto">
                {contentType && (
                    <span
                        className="px-2.5 py-1 bg-gray-800/60 text-gray-400 text-[11px] rounded-md font-mono border border-gray-700/50"
                        title="Content-Type"
                    >
                        {contentType}
                    </span>
                )}

                {status && <StatusBadge status={status} />}
            </div>
        </header>
    );
}
