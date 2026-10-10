import { useEffect, useState } from 'react';
import { nextStatusChange } from '../utils/normalizeEvent.js';

// setTimeout stores its delay as a signed 32-bit integer: anything longer
// than ~24.8 days overflows and fires immediately. A transition further
// away than that is waited for in hops of this length.
const MAX_TIMEOUT = 2 ** 31 - 1;

/**
 * Re-renders the caller at the next moment an "Ongoing" badge among
 * `events` should appear or disappear, then schedules the one after that.
 *
 * One timer per shortcode instance, armed only when something on screen
 * will actually change — no polling. It never decides anything itself:
 * the re-render just makes isOngoing() run again against the real clock,
 * so the timer firing a little late (background tabs throttle timers, a
 * laptop may sleep through one) only delays the update, it can't make it
 * wrong.
 *
 * Ended events keep their card; only the badge goes. Removing them would
 * rearrange the grid under someone who's reading it, and the next filter
 * change or reload drops them anyway, since the server lists by end time.
 *
 * @param {object[]} events Every normalised event currently on screen.
 */
export function useStatusTimer(events) {
  // Bumping this is what re-renders the caller, and — being an effect
  // dependency — what re-arms the timer for the transition after.
  const [tick, setTick] = useState(0);

  useEffect(() => {
    const next = nextStatusChange(events);
    if (next === null) return undefined;

    // Clamped both ways: a transition already due (a late timer) fires
    // straight away, and one beyond MAX_TIMEOUT is reached in hops — each
    // hop's re-render changes nothing, it just re-arms.
    const delay = Math.min(Math.max(next - Date.now(), 0), MAX_TIMEOUT);
    const id = setTimeout(() => setTick((n) => n + 1), delay);

    return () => clearTimeout(id);
    // `events` must be a stable array between renders — App.jsx memoises
    // it — or this would re-arm on every render.
  }, [events, tick]);
}
