import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import { Button, Modal, Form } from 'react-bootstrap';
import axios from 'axios';
import Analytics from './ContentCreatorAnalytics';

/**
 * Content Creator modal component
 */
const ContentCreatorModal = ({ show, onHide, pageID }) => {
  const [loading, setLoading] = useState(false);
  const [prompt, setPrompt] = useState('');
  const [generatedContent, setGeneratedContent] = useState(null);
  const [error, setError] = useState(null);
  const [chatHistory, setChatHistory] = useState([]);
  const [pageStructure, setPageStructure] = useState([]);
  const [step, setStep] = useState('prompt'); // 'prompt' -> 'preview' -> 'done'
  const securityID = window.localStorage.getItem('ss-security-token');

  /**
   * Fetch the structure of the page
   */
  const fetchPageStructure = async () => {
    try {
      const response = await axios.get('/admin/contentcreator/getPageStructure', {
        params: { pageID },
        headers: {
          'X-SecurityToken': securityID,
        },
      });

      if (response.data.success) {
        setPageStructure(response.data.structure);
      } else {
        setError(response.data.error || 'Failed to fetch page structure');
      }
    } catch (err) {
      console.error('Error fetching page structure:', err); // eslint-disable-line no-console
      setError('Failed to fetch page structure. Please try again.');
    }
  };

  useEffect(() => {
    if (show && pageID) {
      fetchPageStructure();
    }
    // Reset states when modal is opened
    if (show) {
      setPrompt('');
      setGeneratedContent(null);
      setError(null);
      setStep('prompt');
    }
  }, [show, pageID]);

  /**
   * Generate content based on the prompt
   */
  const handleGenerate = async () => {
    if (!prompt.trim()) {
      setError('Please enter a prompt');
      return;
    }

    setLoading(true);
    setError(null);

    // Track generation start
    Analytics.trackGenerationStarted(pageID, prompt);
    const startTime = Date.now();

    try {
      const response = await axios.post('/admin/contentcreator/generate', {
        pageID,
        prompt,
      }, {
        headers: {
          'X-SecurityToken': securityID,
        },
      });

      if (response.data.success) {
        setGeneratedContent(response.data.content);
        setChatHistory([
          ...chatHistory,
          { type: 'prompt', content: prompt },
          { type: 'response', content: response.data.content }
        ]);
        setStep('preview');

        // Track successful generation
        Analytics.trackGenerationCompleted(pageID, Date.now() - startTime, true);
      } else {
        setError(response.data.error || 'Failed to generate content');

        // Track failed generation
        Analytics.trackGenerationCompleted(pageID, Date.now() - startTime, false);
        Analytics.trackError(pageID, response.data.error || 'Failed to generate content');
      }
    } catch (err) {
      console.error('Error generating content:', err); // eslint-disable-line no-console
      setError('Failed to generate content. Please try again.');

      // Track error
      Analytics.trackGenerationCompleted(pageID, Date.now() - startTime, false);
      Analytics.trackError(pageID, err.message || 'Network error');
    } finally {
      setLoading(false);
    }
  };

  /**
   * Apply the generated content to the page
   */
  const handleApplyContent = async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await axios.post('/admin/contentcreator/applyContent', {
        pageID,
        content: generatedContent,
      }, {
        headers: {
          'X-SecurityToken': securityID,
        },
      });

      if (response.data.success) {
        setStep('done');

        // Track content application
        Analytics.trackContentApplied(pageID);

        // Reload the page to see the new content
        setTimeout(() => {
          window.location.reload();
        }, 1500);
      } else {
        setError(response.data.error || 'Failed to apply content');

        // Track error
        Analytics.trackError(pageID, response.data.error || 'Failed to apply content');
      }
    } catch (err) {
      console.error('Error applying content:', err); // eslint-disable-line no-console
      setError('Failed to apply content to the page. Please try again.');

      // Track error
      Analytics.trackError(pageID, err.message || 'Network error applying content');
    } finally {
      setLoading(false);
    }
  };

  /**
   * Go back to the prompt step
   */
  const handleBackToPrompt = () => {
    setStep('prompt');
  };

  /**
   * Handle pressing enter to submit the form
   */
  const handleKeyPress = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleGenerate();
    }
  };

  /**
   * Render the page structure
   */
  const renderPageStructure = () => {
    if (!pageStructure || pageStructure.length === 0) {
      return <p>Loading page structure...</p>;
    }

    return (
      <div className="content-creator-page-structure">
        <h5>Page Structure</h5>
        <ul>
          {pageStructure.map((field) => (
            <li key={field.name}> {/* Use field.name as key if unique */}
              <strong>{field.title}</strong> ({field.name})
              {field.description && <span> - {field.description}</span>}

              {field.type === 'ElementalArea' && field.allowedElementTypes && (
                <ul>
                  {field.allowedElementTypes.map((elementType) => (
                    <li key={`${field.name}-${elementType.title}`}> {/* Composite key */}
                      <strong>{elementType.title}</strong>
                      {elementType.fields && (
                        <ul>
                          {elementType.fields.map((elementField) => (
                            <li key={`${field.name}-${elementType.title}-${elementField.name}`}> {/* Composite key */}
                              <span>{elementField.title}</span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
      </div>
    );
  };

  /**
   * Render the generated content
   */
  const renderGeneratedContent = () => {
    if (!generatedContent) {
      return null;
    }

    return (
      <div className="content-creator-generated-content">
        <h5>Generated Content</h5>
        <pre>{JSON.stringify(generatedContent, null, 2)}</pre>
      </div>
    );
  };

  /**
   * Render the chat history
   */
  const renderChatHistory = () => {
    if (chatHistory.length === 0) {
      return null;
    }

    return (
      <div className="content-creator-chat-history">
        {chatHistory.map((message, index) => (
          // Using index as a key here is acceptable if messages don't reorder or have stable IDs
          // If they do, a unique ID from the message object would be better.
          <div key={message.id || `chat-msg-${index}`} className={`chat-message ${message.type}`}>
            {message.type === 'prompt' ? (
              <>
                <div className="message-header">You</div>
                <div className="message-content">{message.content}</div>
              </>
            ) : (
              <>
                <div className="message-header">AI Assistant</div>
                <div className="message-content">
                  <pre>{JSON.stringify(message.content, null, 2)}</pre>
                </div>
              </>
            )}
          </div>
        ))}
      </div>
    );
  };

  /**
   * Render the content based on the current step
   */
  const renderStepContent = () => {
    switch (step) {
      case 'prompt':
        return (
          <>
            <div className="prompt-container">
              <Form>
                <Form.Group controlId="content-creator-prompt">
                  <Form.Label>Enter your content prompt</Form.Label>
                  <Form.Control
                    as="textarea"
                    name="prompt" // Added name attribute
                    rows={4}
                    placeholder="Describe the content you'd like to generate for this page..."
                    value={prompt}
                    onChange={(e) => setPrompt(e.target.value)}
                    onKeyPress={handleKeyPress}
                    disabled={loading}
                  />
                </Form.Group>
              </Form>

              {renderPageStructure()}
              {renderChatHistory()}
            </div>

            <div className="modal-footer">
              <Button variant="secondary" onClick={onHide} disabled={loading}>
                Cancel
              </Button>
              <Button
                variant="primary"
                onClick={handleGenerate}
                disabled={loading || !prompt.trim()}
              >
                {loading ? 'Generating...' : 'Generate Content'}
              </Button>
            </div>
          </>
        );

      case 'preview':
        return (
          <>
            <div className="preview-container">
              {renderGeneratedContent()}
            </div>

            <div className="modal-footer">
              <Button variant="secondary" onClick={handleBackToPrompt} disabled={loading}>
                Back
              </Button>
              <Button
                variant="success"
                onClick={handleApplyContent}
                disabled={loading}
              >
                {loading ? 'Applying...' : 'Apply Content to Page'}
              </Button>
            </div>
          </>
        );

      case 'done':
        return (
          <>
            <div className="done-container">
              <div className="alert alert-success">
                Content has been successfully applied to the page!
              </div>
            </div>

            <div className="modal-footer">
              <Button variant="primary" onClick={onHide}>
                Close
              </Button>
            </div>
          </>
        );

      default:
        return null;
    }
  };

  return (
    <Modal
      show={show}
      onHide={onHide}
      size="lg"
      aria-labelledby="content-creator-modal-title"
      centered
      className="content-creator-modal"
    >
      <Modal.Header closeButton>
        <Modal.Title id="content-creator-modal-title">
          Generate Content with AI
        </Modal.Title>
      </Modal.Header>

      <Modal.Body>
        {error && (
          <div className="alert alert-danger">{error}</div>
        )}

        {renderStepContent()}
      </Modal.Body>
    </Modal>
  );
};

ContentCreatorModal.propTypes = {
  show: PropTypes.bool.isRequired,
  onHide: PropTypes.func.isRequired,
  pageID: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
};

export default ContentCreatorModal;
