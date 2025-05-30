import React, { useEffect, useRef } from 'react';
import PropTypes from 'prop-types';

/**
 * Component to display the chat history
 */
const ChatHistory = ({ history }) => {
  const messageEndRef = useRef(null);

  // Auto scroll to the bottom when messages update
  useEffect(() => {
    if (messageEndRef.current && typeof messageEndRef.current.scrollIntoView === 'function') {
      messageEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [history]);

  if (!history || history.length === 0) {
    return null;
  }

  return (
    <div className="content-creator-chat-history mt-4">
      <h5>Conversation History</h5>
      {history.map((message, index) => (
        <div
          key={message.id || `chat-msg-${index}`}
          className={`chat-message ${message.type} mb-3 p-3 rounded`}
        >
          {message.type === 'prompt' ? (
            <>
              <div className="message-header fw-bold mb-1">You</div>
              <div className="message-content">{message.content}</div>
            </>
          ) : (
            <>
              <div className="message-header fw-bold mb-1">
                AI Assistant
                {message.isStreaming && (
                  <span className="streaming-indicator ms-2">
                    <span className="spinner-grow spinner-grow-sm" role="status" aria-hidden="true" />
                    <span className="ms-1">Generating...</span>
                  </span>
                )}
              </div>
              <div className="message-content">
                {typeof message.content === 'string' ? (
                  <div className="content-text whitespace-pre-wrap">{message.content}</div>
                ) : (
                  <pre className="pre-scrollable">{JSON.stringify(message.content, null, 2)}</pre>
                )}
              </div>
            </>
          )}
        </div>
      ))}
      <div ref={messageEndRef} />
    </div>
  );
};

ChatHistory.propTypes = {
  history: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string,
      type: PropTypes.oneOf(['prompt', 'response']).isRequired,
      content: PropTypes.oneOfType([
        PropTypes.string,
        PropTypes.object
      ]).isRequired,
      isStreaming: PropTypes.bool
    })
  ).isRequired
};

export default ChatHistory;
