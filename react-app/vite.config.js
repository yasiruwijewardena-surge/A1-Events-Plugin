import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * Resolves React to the copy WordPress already loads, instead of bundling
 * a second one.
 *
 * WordPress registers `react` and `react-dom` as script handles and has
 * shipped React 18 since 6.2. class-assets.php declares them as
 * dependencies, so by the time our bundle runs, window.React and
 * window.ReactDOM exist. Without this the bundle carried its own copy:
 * ~155 kB raw / ~49 kB gzipped, the overwhelming majority of which was
 * React itself.
 *
 * The obvious approach — rollupOptions.external plus output.globals —
 * does not work here. `globals` only applies to iife/umd output, and this
 * build stays ES (the plugin enqueues it with type="module"). In an ES
 * bundle an external becomes a bare `import ... from "react"`, which a
 * browser cannot resolve without an import map, and WordPress does not
 * publish one for these handles. So instead of marking them external, we
 * resolve them to tiny virtual modules that re-export the globals. The
 * output stays a real ES module and no React ships in it.
 *
 * This is the one thing @wordpress/scripts gets for free: webpack's
 * externals work with classic script output, which is what WordPress's
 * script system expects. Doing it under Vite means doing it by hand.
 *
 * Build-only (`apply: 'build'`). The dev server has no WordPress and no
 * globals, so `npm run dev` keeps resolving React from node_modules.
 */
function wordpressReactGlobals() {
  const VIRTUAL = {
    react: '\0wp-react',
    'react-dom': '\0wp-react-dom',
    'react-dom/client': '\0wp-react-dom-client',
    'react/jsx-runtime': '\0wp-react-jsx-runtime',
  };

  // Named exports have to be listed statically — an ES module's exports
  // are determined at parse time, so they cannot be spread off an object
  // at runtime. Anything imported from 'react' that is missing here fails
  // the build with "is not exported by", which is the right failure: a
  // loud error at build time rather than an undefined at runtime.
  const REACT_EXPORTS = [
    'Children', 'Component', 'Fragment', 'Profiler', 'PureComponent', 'StrictMode',
    'Suspense', 'cloneElement', 'createContext', 'createElement', 'createFactory',
    'createRef', 'forwardRef', 'isValidElement', 'lazy', 'memo', 'startTransition',
    'useCallback', 'useContext', 'useDebugValue', 'useDeferredValue', 'useEffect',
    'useId', 'useImperativeHandle', 'useInsertionEffect', 'useLayoutEffect', 'useMemo',
    'useReducer', 'useRef', 'useState', 'useSyncExternalStore', 'useTransition', 'version',
  ];

  const REACT_DOM_EXPORTS = [
    'createPortal', 'findDOMNode', 'flushSync', 'render', 'unmountComponentAtNode', 'version',
  ];

  // The guard has to live inside these virtual modules, not in main.jsx.
  // Destructuring the globals happens while the module graph initialises,
  // which is strictly before any statement in main.jsx's body runs — a
  // check there would never be reached. Without this, a missing global
  // surfaces as "Cannot destructure property 'useState' of undefined"
  // pointing into a minified bundle, which says nothing about the actual
  // cause. Throwing here names the cause and what to do about it.
  //
  // This cannot make the failure survivable — there is no React to fall
  // back to — it only makes it diagnosable in seconds instead of an hour.
  const guard = (global, handle) =>
    `if (!window.${global}) { throw new Error("Events Showcase: window.${global} is undefined, so WordPress's \\"${handle}\\" script did not load before this bundle. The plugin declares it as an enqueue dependency, so something on this site has dequeued it, loaded it with async, or reordered the script queue — a JS optimisation plugin is the usual cause. See README.md, 'React comes from WordPress, not from the bundle'."); }`;

  return {
    name: 'wordpress-react-globals',
    apply: 'build',
    enforce: 'pre',
    resolveId(id) {
      return VIRTUAL[id] ?? null;
    },
    load(id) {
      if (id === VIRTUAL.react) {
        return [
          guard('React', 'react'),
          'const React = window.React;',
          'export default React;',
          `export const { ${REACT_EXPORTS.join(', ')} } = React;`,
        ].join('\n');
      }

      if (id === VIRTUAL['react-dom']) {
        return [
          guard('ReactDOM', 'react-dom'),
          'const ReactDOM = window.ReactDOM;',
          'export default ReactDOM;',
          `export const { ${REACT_DOM_EXPORTS.join(', ')} } = ReactDOM;`,
        ].join('\n');
      }

      // createRoot lives on the react-dom global in React 18 — there is no
      // separate global for the /client entry point.
      if (id === VIRTUAL['react-dom/client']) {
        return [
          guard('ReactDOM', 'react-dom'),
          'const ReactDOM = window.ReactDOM;',
          'export const createRoot = ReactDOM.createRoot;',
          'export const hydrateRoot = ReactDOM.hydrateRoot;',
          'export default { createRoot, hydrateRoot };',
        ].join('\n');
      }

      if (id === VIRTUAL['react/jsx-runtime']) {
        // React's UMD build exposes no jsx-runtime, so it is rebuilt here
        // on top of createElement. jsx() and jsxs() differ only in whether
        // the compiler knew the children list was static, which matters
        // for React's key warnings and not for the elements produced — so
        // one implementation serves both.
        //
        // createElement reads `key` and `ref` off the props object it is
        // given, which is why they are passed through in props rather than
        // handled separately here.
        return [
          guard('React', 'react'),
          'const React = window.React;',
          'export const Fragment = React.Fragment;',
          'export function jsx(type, config, maybeKey) {',
          '  const { children, ...props } = config;',
          '  if (maybeKey !== undefined) props.key = maybeKey;',
          '  return children === undefined',
          '    ? React.createElement(type, props)',
          '    : React.createElement(type, props, children);',
          '}',
          'export const jsxs = jsx;',
        ].join('\n');
      }

      return null;
    },
  };
}

// Build output is written straight into the WordPress plugin's assets
// folder so the plugin can enqueue it via manifest.json (hashed filenames).
// See wordpress-plugin/events-showcase/includes/class-assets.php.
export default defineConfig({
  plugins: [react(), wordpressReactGlobals()],
  base: '',
  build: {
    outDir: '../wordpress-plugin/events-showcase/assets/build',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: 'src/main.jsx',
    },
  },
});
