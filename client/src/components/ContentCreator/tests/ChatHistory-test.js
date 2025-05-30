/* eslint-env jest */
import React from 'react';
import { render } from '@testing-library/react';
import '@testing-library/jest-dom';
import ChatHistory from '../ChatHistory';

// Sample chat history data for testing
const mockHistory = [
  {
    id: '1',
    type: 'prompt',
    content: 'Test user message',
  },
  {
    id: '2',
    type: 'response',
    content: 'Test AI response',
  },
  {
    id: '3',
    type: 'response',
    content: { data: 'Test object response' },
    isStreaming: true,
  },
];

describe('ChatHistory', () => {
  beforeEach(() => {
    // Mock scrollIntoView if it doesn't exist
    if (typeof HTMLElement.prototype.scrollIntoView !== 'function') {
      HTMLElement.prototype.scrollIntoView = jest.fn();
    }
  });

  it('renders correctly with history', () => {
    const { container, getByText, getAllByText } = render(
      <ChatHistory history={mockHistory} />
    );

    // Check if the component renders
    const conversationTitle = getByText('Conversation History');
    expect(conversationTitle).toBeTruthy();

    // Check if user message is rendered
    const userLabel = getByText('You');
    expect(userLabel).toBeTruthy();

    const userMessage = getByText('Test user message');
    expect(userMessage).toBeTruthy();

    // Check if AI response is rendered
    const aiLabels = getAllByText('AI Assistant');
    expect(aiLabels.length).toBe(2); // Ensure both AI assistant labels are present

    const aiResponse = getByText('Test AI response');
    expect(aiResponse).toBeTruthy();

    // Check if the streaming indicator is shown
    const generatingText = getByText('Generating...');
    expect(generatingText).toBeTruthy();

    // Check if object content is rendered as JSON
    const jsonPreElement = container.querySelector('.pre-scrollable');
    expect(jsonPreElement).toBeTruthy();
  });

  it('returns null when history is empty', () => {
    const { container } = render(<ChatHistory history={[]} />);
    expect(container.firstChild).toBeNull();
  });

  it('handles scrolling without errors', () => {
    // This test just ensures that the scrollIntoView logic doesn't throw errors
    render(<ChatHistory history={mockHistory} />);

    // If we get here without errors, the test passes
    expect(true).toBe(true);
  });
});
