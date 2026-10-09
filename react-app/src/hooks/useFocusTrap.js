import { useEffect } from 'react';

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';

// Traps Tab/Shift+Tab focus inside `containerRef` while mounted, and
// calls `onEscape` when the Escape key is pressed. Used by EventModal.
export function useFocusTrap(containerRef, onEscape) {
  useEffect(() => {
    const handleKeyDown = (e) => {
      if (e.key === 'Escape') {
        onEscape();
        return;
      }

      if (e.key !== 'Tab' || !containerRef.current) return;

      const focusable = containerRef.current.querySelectorAll(FOCUSABLE_SELECTOR);
      if (focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const { activeElement } = document;

      // The dialog itself holds focus right after opening (EventModal
      // focuses it, tabIndex -1), and it is not in `focusable`. Without
      // this branch Shift+Tab from there isn't "on first", so the browser
      // moves focus backwards out of the portal onto the page behind.
      // Same if focus has somehow already left the container.
      if (activeElement === containerRef.current || !containerRef.current.contains(activeElement)) {
        e.preventDefault();
        (e.shiftKey ? last : first).focus();
      } else if (e.shiftKey && activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [containerRef, onEscape]);
}
