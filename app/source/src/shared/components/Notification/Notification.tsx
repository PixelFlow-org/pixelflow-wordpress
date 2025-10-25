import React from 'react';

type NotificationType = 'success' | 'error' | 'info' | 'warning';

interface NotificationProps {
  message: string;
  type: NotificationType;
}

const Notification: React.FC<NotificationProps> = ({ message, type }) => {
  const baseStyle =
    'flex items-center gap-3 p-4 rounded-2xl shadow-lg text-sm font-medium transition-all duration-300 border';

  let customStyle: React.CSSProperties = {};
  
  if (type === 'success') {
    customStyle = {
      backgroundColor: '#f0fdf4',
      color: '#166534',
      borderColor: '#bbf7d0',
    };
  }
  if (type === 'error') {
    customStyle = {
      backgroundColor: '#fef2f2',
      color: '#991b1b',
      borderColor: '#fecaca',
    };
  }
  if (type === 'info') {
    customStyle = {
      backgroundColor: '#eff6ff',
      color: '#1e40af',
      borderColor: '#bfdbfe',
    };
  }
  if (type === 'warning') {
    customStyle = {
      backgroundColor: '#fefce8',
      color: '#854d0e',
      borderColor: '#fef08a',
    };
  }

  const icon = (() => {
    switch (type) {
      case 'success':
        return (
          <svg
            className="w-5 h-5 text-green-600"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            viewBox="0 0 24 24"
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        );
      case 'error':
        return (
          <svg
            className="w-5 h-5 text-red-600"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            viewBox="0 0 24 24"
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        );
      case 'warning':
        return (
          <svg
            className="w-5 h-5 text-yellow-600"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a1 1 0 00.86 1.5h18.64a1 1 0 00.86-1.5L13.71 3.86a1 1 0 00-1.72 0z"
            />
          </svg>
        );
      default:
        return (
          <svg
            className="w-5 h-5 text-blue-600"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M13 16h-1v-4h-1m1-4h.01M12 18a6 6 0 100-12 6 6 0 000 12z"
            />
          </svg>
        );
    }
  })();

  return (
    <div className={baseStyle} style={customStyle}>
      {icon}
      <span>{message}</span>
    </div>
  );
};

export default Notification;
