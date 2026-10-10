// Inline SVG icons shared by the card and the hero, rather than an icon
// font or a package — three glyphs don't justify a dependency, and
// inlining means they inherit currentColor and can't arrive after the
// text they belong to. All are aria-hidden: each sits next to a text label
// that already says the same thing.
//
// `className` is passed in rather than fixed, so each consumer sizes the
// icon from its own stylesheet block.

export function PinIcon({ className }) {
  return (
    <svg className={className} viewBox="0 0 16 16" aria-hidden="true" focusable="false">
      <path
        d="M8 1.5a4.5 4.5 0 0 0-4.5 4.5c0 3.4 4.5 8.5 4.5 8.5s4.5-5.1 4.5-8.5A4.5 4.5 0 0 0 8 1.5Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.3"
      />
      <circle cx="8" cy="6" r="1.7" fill="none" stroke="currentColor" strokeWidth="1.3" />
    </svg>
  );
}

export function TagIcon({ className }) {
  return (
    <svg className={className} viewBox="0 0 16 16" aria-hidden="true" focusable="false">
      <path
        d="M2.5 7.3V2.5h4.8l6.2 6.2-4.8 4.8L2.5 7.3Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.3"
        strokeLinejoin="round"
      />
      <circle cx="5.4" cy="5.4" r="1" fill="currentColor" />
    </svg>
  );
}

export function StarIcon({ className }) {
  return (
    <svg className={className} viewBox="0 0 16 16" aria-hidden="true" focusable="false">
      <path
        d="M8 1.6l1.95 3.95 4.35.63-3.15 3.07.74 4.33L8 11.53 4.11 13.58l.74-4.33L1.7 6.18l4.35-.63L8 1.6Z"
        fill="currentColor"
      />
    </svg>
  );
}
