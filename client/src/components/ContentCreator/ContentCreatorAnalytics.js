import $ from 'jquery';

/**
 * Class for tracking content generation analytics
 */
class ContentCreatorAnalytics {
  constructor() {
    this.enabled = true;
    this.endpoint = '/admin/contentcreator/analytics';
    this.events = [];
  }

  /**
   * Enable or disable analytics tracking
   * @param {boolean} enabled
   */
  setEnabled(enabled) {
    this.enabled = !!enabled;
    return this;
  }

  /**
   * Track a content generation event
   * @param {string} eventType - The type of event (e.g. 'generate', 'apply')
   * @param {object} data - Additional data about the event
   */
  trackEvent(eventType, data) {
    if (!this.enabled) {
      return;
    }

    const event = {
      type: eventType,
      timestamp: new Date().toISOString(),
      data: { ...data },
    };

    this.events.push(event);

    // Send the event immediately
    this.sendEvent(event);
  }

  /**
   * Track generation started
   * @param {string} dataObjectID - The ID of the DataObject being generated
   * @param {string} dataObjectClass - The ClassName of the DataObject
   * @param {string} prompt - The prompt used for generation
   */
  trackGenerationStarted(dataObjectID, dataObjectClass, prompt) {
    this.trackEvent('generation_started', { dataObjectID, dataObjectClass, prompt });
  }

  /**
   * Track generation completed
   * @param {string} dataObjectID - The ID of the DataObject being generated
   * @param {string} dataObjectClass - The ClassName of the DataObject
   * @param {number} duration - The duration in milliseconds
   * @param {boolean} success - Whether the generation was successful
   */
  trackGenerationCompleted(dataObjectID, dataObjectClass, duration, success) {
    this.trackEvent('generation_completed', { dataObjectID, dataObjectClass, duration, success });
  }

  /**
   * Track content applied to page
   * @param {string} dataObjectID - The ID of the DataObject being generated
   * @param {string} dataObjectClass - The ClassName of the DataObject
   */
  trackContentApplied(dataObjectID, dataObjectClass) {
    this.trackEvent('content_applied', { dataObjectID, dataObjectClass });
  }

  /**
   * Track error
   * @param {string} dataObjectID - The ID of the DataObject being generated
   * @param {string} dataObjectClass - The ClassName of the DataObject
   * @param {string} error - The error message
   */
  trackError(dataObjectID, dataObjectClass, error) {
    this.trackEvent('error', { dataObjectID, dataObjectClass, error });
  }

  /**
   * Send an event to the server
   * @param {object} event - The event to send
   * @private
   */
  sendEvent(event) {
    $.ajax({
      url: this.endpoint,
      method: 'POST',
      contentType: 'application/json',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
      data: JSON.stringify(event),
      error: (xhr, status, error) => {
        console.error('Failed to track content creator event:', error); // eslint-disable-line no-console
      },
    });
  }
}

// Create singleton instance
const instance = new ContentCreatorAnalytics();

export default instance;
