import { useState, useRef, useCallback } from 'react';

const POLL_INTERVAL = 5000;
const MAX_ATTEMPTS = 360;

export default function usePolling(apiKey) {
    const [isPolling, setIsPolling] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const [logs, setLogs] = useState([]);
    const [lastResponse, setLastResponse] = useState(null);
    const abortRef = useRef(false);

    const start = useCallback(async (transcriptionId, onResponse) => {
        if (!apiKey || !transcriptionId) return;

        abortRef.current = false;
        setIsPolling(true);
        setLogs([]);
        setAttempt(0);

        for (let i = 1; i <= MAX_ATTEMPTS; i++) {
            if (abortRef.current) break;

            setAttempt(i);

            try {
                const res = await fetch(`/api/v1/process/${transcriptionId}`, {
                    headers: { 'X-API-Key': apiKey }
                });

                const data = await res.json();
                const logEntry = {
                    attempt: i,
                    timestamp: new Date().toISOString(),
                    status: res.status,
                    data
                };

                setLogs(prev => [...prev, logEntry]);
                setLastResponse({ status: res.status, data });
                onResponse?.({ status: res.status, data });

                if (data.status === 'COMPLETED' || data.status === 'FAILED') {
                    setIsPolling(false);
                    break;
                }
            } catch (error) {
                const logEntry = {
                    attempt: i,
                    timestamp: new Date().toISOString(),
                    error: error.message
                };
                setLogs(prev => [...prev, logEntry]);
            }

            if (i < MAX_ATTEMPTS && !abortRef.current) {
                await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
            }
        }

        setIsPolling(false);
    }, [apiKey]);

    const stop = useCallback(() => {
        abortRef.current = true;
        setIsPolling(false);
    }, []);

    const reset = useCallback(() => {
        stop();
        setLogs([]);
        setAttempt(0);
        setLastResponse(null);
    }, [stop]);

    return {
        isPolling,
        attempt,
        logs,
        lastResponse,
        start,
        stop,
        reset
    };
}
