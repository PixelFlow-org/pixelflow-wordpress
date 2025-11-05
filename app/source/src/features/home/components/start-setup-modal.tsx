/** External libraries */
import { useEffect, ReactElement } from 'react';
import { createPortal } from 'react-dom';

/** UI Components */
import { Button } from '@pixelflow-org/plugin-ui';

/** Types */
type StartSetupModalProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

/**
 * Start Setup Modal component
 * @description Custom modal for starting the setup process that doesn't use Radix Dialog and doesn't set pointer-events: none
 */
const StartSetupModal = ({ open, onOpenChange }: StartSetupModalProps): ReactElement | null => {
  // Handle ESC key press to close modal
  useEffect(() => {
    if (!open) return;

    const handleEscape = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        onOpenChange(false);
      }
    };

    document.addEventListener('keydown', handleEscape);
    return () => {
      document.removeEventListener('keydown', handleEscape);
    };
  }, [open, onOpenChange]);

  if (!open) return null;

  // Handle overlay click to close modal
  const handleOverlayClick = (event: React.MouseEvent<HTMLDivElement>): void => {
    if (event.target === event.currentTarget) {
      onOpenChange(false);
    }
  };

  return createPortal(
    <div
      className="start-setup-modal-pf fixed inset-0 z-101 bg-black/40 backdrop-blur-sm"
      onClick={handleOverlayClick}
      role="dialog"
      aria-modal="true"
      aria-labelledby="start-setup-modal-title"
    >
      <div
        className={`
          fixed top-1/2 left-1/2 z-102 !max-h-[480px] !w-[360px] -translate-x-1/2 -translate-y-1/2
          rounded-2xl shadow-[0_0_0_1px_rgba(51,51,51,0.04),0_1px_1px_0.5px_rgba(51,51,51,0.04),0_3px_3px_-1.5px_rgba(51,51,51,0.02),0_6px_6px_-3px_rgba(51,51,51,0.04),0_12px_12px_-6px_rgba(51,51,51,0.04),0_24px_24px_-12px_rgba(51,51,51,0.04),0_48px_48px_-24px_rgba(51,51,51,0.04),inset_0_-1px_1px_-0.5px_rgba(51,51,51,0.06)]
          flex flex-col
          pf-modal
        `}
        onClick={(e) => e.stopPropagation()}
      >
        {/* Modal Header */}
        <div className="!mh-[52px] flex !w-full flex-row items-center justify-center gap-3 !rounded-t-[14px] !border-b !border-secondary !px-5 !py-3 pf-modal__header">
          <span
            id="start-setup-modal-title"
            className="!font-magnetik text-center !text-[16px] !leading-5 !font-semibold !tracking-[-0.006em] pf-modal__header-text text-foreground"
            style={{ fontFeatureSettings: '"calt" off, "liga" off' }}
          >
            Start your Setup
          </span>
        </div>

        {/* Modal Body */}
        <div className="pf-modal__body flex !w-full flex-col items-start justify-center !px-5 !py-3">
          <p className="text-foreground text-[14px]">
            You do not have any pixel configured yet. Please click the button below to start your
            setup process in PixelFlow dashboard.
          </p>
        </div>

        {/* Modal Footer */}
        <div className="!mh-[72px] flex !w-full flex-row items-center gap-3 !rounded-b-[14px] !border-t !border-secondary !px-5 !py-3 pf-modal__footer">
          <div className="flex !w-full flex-row items-center justify-center gap-3">
            <Button.Root
              variant="primary"
              mode="filled"
              size="small"
              className="!w-[160px] min-w-[160px] !max-w-[160px]"
              onClick={() =>
                window.open('https://dashboard.pixelflow.so/dashboard/overview', '_blank')
              }
            >
              Start Setup
            </Button.Root>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
};

export default StartSetupModal;
