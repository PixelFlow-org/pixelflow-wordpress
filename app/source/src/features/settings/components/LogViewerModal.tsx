/**
 * @fileoverview Log Viewer Modal component
 * @description Displays debug log file contents in a modal dialog
 */

/** External libraries */
import { useEffect, ReactElement } from 'react';
import { createPortal } from 'react-dom';

type LogViewerModalProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  content: string;
  isLoading: boolean;
  error?: string;
};

const LogViewerModal = ({
  open,
  onOpenChange,
  content,
  isLoading,
  error,
}: LogViewerModalProps): ReactElement | null => {
  useEffect(() => {
    if (!open) return;
    const handleEscape = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') onOpenChange(false);
    };
    document.addEventListener('keydown', handleEscape);
    return () => document.removeEventListener('keydown', handleEscape);
  }, [open, onOpenChange]);

  if (!open) return null;

  return createPortal(
    <div
      className="fixed inset-0 z-101 bg-black/40 backdrop-blur-sm"
      onClick={(e) => {
        if (e.target === e.currentTarget) onOpenChange(false);
      }}
      role="dialog"
      aria-modal="true"
      aria-labelledby="log-viewer-modal-title"
    >
      <div
        className="fixed top-1/2 left-1/2 z-102 w-[720px] max-w-[95vw] max-h-[80vh] -translate-x-1/2 -translate-y-1/2 rounded-2xl flex flex-col pf-modal shadow-[0_0_0_1px_rgba(51,51,51,0.04),0_1px_1px_0.5px_rgba(51,51,51,0.04),0_3px_3px_-1.5px_rgba(51,51,51,0.02),0_6px_6px_-3px_rgba(51,51,51,0.04),0_12px_12px_-6px_rgba(51,51,51,0.04),0_24px_24px_-12px_rgba(51,51,51,0.04)]"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between rounded-t-[14px] border-b border-secondary px-5 py-3 pf-modal__header">
          <span
            id="log-viewer-modal-title"
            className="font-magnetik text-[16px] font-semibold text-foreground"
            style={{ fontFeatureSettings: '"calt" off, "liga" off' }}
          >
            Debug Log
          </span>
          <button
            onClick={() => onOpenChange(false)}
            className="text-foreground opacity-60 hover:opacity-100 text-lg leading-none"
            aria-label="Close"
          >
            ✕
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-auto min-h-0 px-5 py-3 pf-modal__body">
          {isLoading && <p className="text-sm text-foreground">Loading…</p>}
          {!isLoading && error && <p className="text-sm text-red-500">{error}</p>}
          {!isLoading && !error && !content && (
            <p className="text-sm text-foreground italic">Log file is empty.</p>
          )}
          {!isLoading && !error && content && (
            <pre className="text-xs font-mono whitespace-pre-wrap break-all text-foreground">
              {content}
            </pre>
          )}
        </div>

        {/* Footer */}
        <div className="flex justify-end rounded-b-[14px] border-t border-secondary px-5 py-3 pf-modal__footer">
          <button
            onClick={() => onOpenChange(false)}
            className="text-xs text-foreground border border-secondary rounded px-3 py-1 hover:opacity-80"
          >
            Close
          </button>
        </div>
      </div>
    </div>,
    document.body,
  );
};

export default LogViewerModal;
