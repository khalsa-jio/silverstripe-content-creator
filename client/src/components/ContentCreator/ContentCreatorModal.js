import React, { useState, useEffect } from 'react';
import PropTypes from 'prop-types';
import {
  Modal,
  ModalHeader,
  ModalBody,
  ModalTitle,
  Alert,
} from 'react-bootstrap';
import axios from 'axios';
import Analytics from './ContentCreatorAnalytics';

import PageStructure from './PageStructure';
import ChatHistory from './ChatHistory';
import PromptForm from './PromptForm';
import ContentPreview from './ContentPreview';
import CompletionMessage from './CompletionMessage';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Content Creator modal component
 */
const ContentCreatorModal = ({ show, onHide, dataObjectID, dataObjectClass }) => {
  const [loading, setLoading] = useState(false);
  const [prompt, setPrompt] = useState('');
  const [generatedContent, setGeneratedContent] = useState(null);
  const [error, setError] = useState(null);
  const [chatHistory, setChatHistory] = useState([]);
  const [pageStructure, setPageStructure] = useState([]);
  const [step, setStep] = useState('prompt');
  const startTime = Date.now();

  /**
   * Fetch the structure of the page
   */
  const fetchPageStructure = async () => {
    try {
      const response = await axios.get('/admin/contentcreator/getPageStructure', {
        params: { dataObjectID, dataObjectClass },
      });

      if (response.data.success) {
        setPageStructure(response.data.structure);
      } else {
        setError(response.data.error || 'Failed to fetch page structure');
      }
    } catch (err) {
      setError('Failed to fetch page structure. Please try again.');
    }
  };

  useEffect(() => {
    if (show && dataObjectID && dataObjectClass) {
      fetchPageStructure();
    }
    // Reset states when modal is opened
    if (show) {
      setPrompt('');
      setGeneratedContent(null);
      setError(null);
      setStep('prompt');
    }
  }, [show, dataObjectID, dataObjectClass]);

  /**
   * Handle content generation with traditional request (non-streaming)
   */
  const handleNonStreamingGenerate = async () => {
    try {
      const response = await axios.post('/admin/contentcreator/generate', {
        dataObjectID,
        dataObjectClass,
        prompt,
      });

      if (response.data.success) {
        setGeneratedContent(response.data.content);
        setChatHistory(prevHistory => {
          // Replace the last item (which should be the "Generating..." placeholder)
          const newHistory = [...prevHistory];
          newHistory[newHistory.length - 1] = {
            type: 'response',
            content: response.data.content
          };
          return newHistory;
        });
        setStep('preview');

        // Track successful generation
        Analytics.trackGenerationCompleted(dataObjectID, dataObjectClass, Date.now() - startTime, true);
      } else {
        setError(response.data.error || 'Failed to generate content');

        // Track failed generation
        Analytics.trackGenerationCompleted(dataObjectID, dataObjectClass, Date.now() - startTime, false);
        Analytics.trackError(dataObjectID, dataObjectClass, response.data.error || 'Failed to generate content');
      }
    } catch (err) {
      console.error('Error generating content:', err); // eslint-disable-line no-console
      setError('Failed to generate content. Please try again.');

      // Track error
      Analytics.trackGenerationCompleted(dataObjectID, dataObjectClass, Date.now() - startTime, false);
      Analytics.trackError(dataObjectID, dataObjectClass, err.message || 'Network error');
    } finally {
      setLoading(false);
    }
  };

  /**
   * Handle content generation with streaming using Server-Sent Events (SSE)
   */
  const handleStreamingGenerate = async () => new Promise((resolve, reject) => {
    // Create a unique request ID to append to the URL to prevent caching
    const requestId = Date.now();

    // Create URL for the EventSource with query parameters
    const url = new URL('/admin/contentcreator/generate', window.location.origin);
    url.searchParams.append('dataObjectID', dataObjectID);
    url.searchParams.append('dataObjectClass', dataObjectClass);
    url.searchParams.append('prompt', encodeURIComponent(prompt));
    url.searchParams.append('streaming', 'true');
    url.searchParams.append('_', requestId);

    // Track streamed content
    let streamedContent = '';

    // Create a new EventSource for SSE communication
    const eventSource = new EventSource(url.toString());

    // Set up event handlers
    eventSource.onopen = () => {
      console.log('SSE connection established'); // eslint-disable-line no-console
    };

    // Handle different event types from the server
    eventSource.addEventListener('start', (event) => {
      try {
        const data = JSON.parse(event.data);
        console.log('Generation started:', data); // eslint-disable-line no-console
      } catch (e) {
        console.error('Error parsing start event:', e); // eslint-disable-line no-console
      }
    });

    // Handle content chunks
    eventSource.addEventListener('chunk', (event) => {
      try {
        const data = JSON.parse(event.data);
        if (data.text) {
          streamedContent += data.text;

          // Update chat history with current content
          setChatHistory(prevHistory => {
            const newHistory = [...prevHistory];
            newHistory[newHistory.length - 1] = {
              type: 'response',
              content: streamedContent,
              isStreaming: true
            };
            return newHistory;
          });
        }
      } catch (e) {
        console.error('Error parsing chunk event:', e); // eslint-disable-line no-console
      }
    });

    // Handle completion with the structured content
    eventSource.addEventListener('complete', (event) => {
      try {
        const data = JSON.parse(event.data);
        if (data.content) {
          // Set the final, parsed content
          setGeneratedContent(data.content);

          // Update chat history with final content
          setChatHistory(prevHistory => {
            const newHistory = [...prevHistory];
            newHistory[newHistory.length - 1] = {
              type: 'response',
              content: streamedContent,
              isStreaming: false
            };
            return newHistory;
          });

          // Move to preview step
          setStep('preview');

          // Track successful generation
          Analytics.trackGenerationCompleted(dataObjectID, dataObjectClass, Date.now() - startTime, true);
        }
      } catch (e) {
        console.error('Error parsing complete event:', e); // eslint-disable-line no-console
      }
    });

    // Handle errors from the server
    eventSource.addEventListener('error', (event) => {
      try {
        if (event.data) {
          const data = JSON.parse(event.data);
          if (data.error) {
            setError(data.error);

            // Track error
            Analytics.trackGenerationCompleted(dataObjectID, dataObjectClass, Date.now() - startTime, false);
            Analytics.trackError(dataObjectID, dataObjectClass, data.error);
          }
        }
      } catch (e) {
        console.error('Error parsing error event:', e); // eslint-disable-line no-console
        setError('Error processing response. Please try again.');
      }

      // Close the connection on error
      eventSource.close();
      reject(new Error('SSE error event received'));
    });

    // Handle end of stream
    eventSource.addEventListener('end', () => {
      // Streaming completed
      setLoading(false);
      eventSource.close();
      resolve();
    });

    // Handle standard SSE error - connection issues, etc.
    eventSource.onerror = (err) => {
      console.error('SSE connection error:', err); // eslint-disable-line no-console
      setError('Connection to server interrupted. Please try again.');

      // Track error
      Analytics.trackGenerationCompleted(dataObjectID, dataObjectClass, Date.now() - startTime, false);
      Analytics.trackError(dataObjectID, dataObjectClass, 'SSE connection error');

      // Close the connection
      eventSource.close();
      setLoading(false);
      reject(err);
    };

    // Set up a timeout in case the server never responds
    const timeout = setTimeout(() => {
      setError('Request timed out. Please try again.');
      eventSource.close();
      setLoading(false);
      reject(new Error('SSE request timed out'));
    }, 60000); // 60 second timeout

    // Clear timeout when we get any event
    ['start', 'chunk', 'complete', 'end'].forEach(eventType => {
      eventSource.addEventListener(eventType, () => {
        clearTimeout(timeout);
      });
    });
  });

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
    Analytics.trackGenerationStarted(dataObjectID, dataObjectClass, prompt);

    // Add to chat history immediately
    setChatHistory([
      ...chatHistory,
      { type: 'prompt', content: prompt },
      { type: 'response', content: 'Generating...', isStreaming: true }
    ]);

    // Check if the browser supports EventSource for SSE
    if (typeof EventSource !== 'undefined') {
      try {
        // Use streaming API
        await handleStreamingGenerate();
      } catch (err) {
        console.error('Error with streaming content generation:', err); // eslint-disable-line no-console
        // Fallback to non-streaming method
        await handleNonStreamingGenerate();
      }
    } else {
      // Browser doesn't support SSE, use non-streaming method
      await handleNonStreamingGenerate();
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
        dataObjectID,
        dataObjectClass,
        content: generatedContent,
      });

      if (response.data.success) {
        setStep('done');

        // Track content application
        Analytics.trackContentApplied(dataObjectID, dataObjectClass);

        // Reload the page to see the new content
        setTimeout(() => {
          window.location.reload();
        }, 1500);
      } else {
        setError(response.data.error || 'Failed to apply content');

        // Track error
        Analytics.trackError(dataObjectID, dataObjectClass, response.data.error || 'Failed to apply content');
      }
    } catch (err) {
      console.error('Error applying content:', err); // eslint-disable-line no-console
      setError('Failed to apply content to the page. Please try again.');

      // Track error
      Analytics.trackError(dataObjectID, dataObjectClass, err.message || 'Network error applying content');
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
   * Render the content based on the current step
   */
  const renderStepContent = () => {
    switch (step) {
      case 'prompt':
        return (
          <div className="prompt-step">
            <PromptForm
              prompt={prompt}
              setPrompt={setPrompt}
              onSubmit={handleGenerate}
              loading={loading}
              onCancel={onHide}
            />

            <div className="mt-4">
              <PageStructure structure={pageStructure} />
            </div>

            {chatHistory.length > 0 && (
              <div className="mt-4">
                <ChatHistory history={chatHistory} />
              </div>
            )}
          </div>
        );

      case 'preview':
        return (
          <ContentPreview
            content={generatedContent}
            onApply={handleApplyContent}
            onBack={handleBackToPrompt}
            loading={loading}
          />
        );

      case 'done':
        return (
          <CompletionMessage onClose={onHide} />
        );

      default:
        return null;
    }
  };

  return (
    <Modal
      show={show}
      onHide={onHide}
      size="xl"
      aria-labelledby="content-creator-modal-title"
      centered
      className="content-creator-modal"
    >
      <ModalHeader>
        <ModalTitle id="content-creator-modal-title">
          Generate Content with AI
        </ModalTitle>
      </ModalHeader>

      <ModalBody>
        {error && (
          <Alert variant="danger">{error}</Alert>
        )}

        {renderStepContent()}
      </ModalBody>
    </Modal>
  );
};

ContentCreatorModal.propTypes = {
  show: PropTypes.bool.isRequired,
  onHide: PropTypes.func.isRequired,
  dataObjectID: PropTypes.oneOfType([PropTypes.string, PropTypes.number]),
  dataObjectClass: PropTypes.string,
};

export default ContentCreatorModal;
