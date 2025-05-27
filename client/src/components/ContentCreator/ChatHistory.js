import React from 'react';
import PropTypes from 'prop-types';

/**
 * Component to display the chat history
 */
const ChatHistory = ({ history }) => {
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
              <div className="message-header fw-bold mb-1">AI Assistant</div>
              <div className="message-content">
                <pre className="pre-scrollable">{JSON.stringify(message.content, null, 2)}</pre>
              </div>
            </>
          )}
        </div>
      ))}
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
      ]).isRequired
    })
  ).isRequired
};

export default ChatHistory;
