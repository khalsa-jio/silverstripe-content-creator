import $ from 'jquery';

/**
 * Class for tracking content generation analytics
 */
class ContentCreatorAnalytics {
  constructor() {
    this.enabled = true;
    this.endpoint = '/admin/contentcreator/analytics';
    this.events = [];
    this.securityToken = window.localStorage.getItem('ss-security-token');
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
      data: { ...data }
    };

    this.events.push(event);

    // Send the event immediately
    this.sendEvent(event);
  }

  /**
   * Track generation started
   * @param {string} pageID - The ID of the page being generated
   * @param {string} prompt - The prompt used for generation
   */
  trackGenerationStarted(pageID, prompt) {
    this.trackEvent('generation_started', { pageID, prompt });
  }

  /**
   * Track generation completed
   * @param {string} pageID - The ID of the page being generated
   * @param {number} duration - The duration in milliseconds
   * @param {boolean} success - Whether the generation was successful
   */
  trackGenerationCompleted(pageID, duration, success) {
    this.trackEvent('generation_completed', { pageID, duration, success });
  }

  /**
   * Track content applied to page
   * @param {string} pageID - The ID of the page being generated
   */
  trackContentApplied(pageID) {
    this.trackEvent('content_applied', { pageID });
  }

  /**
   * Track error
   * @param {string} pageID - The ID of the page being generated
   * @param {string} error - The error message
   */
  trackError(pageID, error) {
    this.trackEvent('error', { pageID, error });
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
        'X-SecurityToken': this.securityToken
      },
      data: JSON.stringify(event),
      error: (xhr, status, error) => {
        console.error('Failed to track content creator event:', error); // eslint-disable-line no-console
      }
    });
  }
}

// Create singleton instance
const instance = new ContentCreatorAnalytics();

export default instance;
