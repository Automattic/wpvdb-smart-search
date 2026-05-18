import { useEffect, useMemo, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import Form from './Form';
import HelpPopover from './HelpPopover';
import LanguageToggle from './LanguageToggle';
import Results from './Results';
import ThemeToggle from './ThemeToggle';

const VALID_MODES = [ 'hybrid', 'dense', 'sparse' ];
const DEFAULT_MODE = 'dense';
const DEFAULT_LIMIT = 10;

function sampleExamples( pool, count ) {
	if ( ! Array.isArray( pool ) || pool.length === 0 ) {
		return [];
	}
	const copy = pool.slice();
	for ( let i = copy.length - 1; i > 0; i-- ) {
		const j = Math.floor( Math.random() * ( i + 1 ) );
		[ copy[ i ], copy[ j ] ] = [ copy[ j ], copy[ i ] ];
	}
	return copy.slice( 0, count );
}

function groupByPost( rows ) {
	const byPost = new Map();
	rows.forEach( ( r ) => {
		let entry = byPost.get( r.post_id );
		if ( ! entry ) {
			entry = {
				post_id: r.post_id,
				title: r.title,
				link: r.link,
				best_distance: r.distance,
				best_sim: r.similarity,
				best_sparse: r.sparse_score,
				sources: new Set( r.sources ),
				chunks: [],
			};
			byPost.set( r.post_id, entry );
		}
		if (
			r.distance !== null &&
			( entry.best_distance === null || r.distance < entry.best_distance )
		) {
			entry.best_distance = r.distance;
			entry.best_sim = r.similarity;
		}
		if (
			r.sparse_score !== null &&
			( entry.best_sparse === null || r.sparse_score > entry.best_sparse )
		) {
			entry.best_sparse = r.sparse_score;
		}
		( r.sources || [] ).forEach( ( s ) => entry.sources.add( s ) );
		entry.chunks.push( r );
	} );
	return Array.from( byPost.values() ).map( ( e ) => ( {
		...e,
		sources: Array.from( e.sources ),
	} ) );
}

export default function App( { config } ) {
	const restUrl = config.restUrl || '';
	const siteName = config.siteName || '';
	const homeUrl = config.homeUrl || '#';
	const rateMax = config.rateMax || 20;
	const maxQuery = config.maxQuery || 500;
	const maxResults = config.maxResults || 20;
	const examplesPool = config.examplesPool || [];
	const examplesVisible = config.examplesVisible || 5;
	const placeholders = config.placeholders || [];

	const examples = useMemo(
		() => sampleExamples( examplesPool, examplesVisible ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[]
	);
	const placeholder = useMemo(
		() =>
			placeholders.length > 0
				? placeholders[ Math.floor( Math.random() * placeholders.length ) ]
				: '',
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[]
	);

	// Read shareable search params off the URL on first render so the user
	// lands straight on the results when following a link.
	// Uses `?q=` (not `?s=`) to avoid colliding with WP's built-in search var.
	const initialFromUrl = useMemo( () => {
		if ( typeof window === 'undefined' ) {
			return { q: '', mode: DEFAULT_MODE, limit: DEFAULT_LIMIT };
		}
		const params = new URLSearchParams( window.location.search );
		const q = params.get( 'q' ) || '';
		const rawMode = params.get( 'mode' );
		const m = VALID_MODES.includes( rawMode ) ? rawMode : DEFAULT_MODE;
		const rawLimit = parseInt( params.get( 'limit' ), 10 );
		const n = Number.isFinite( rawLimit )
			? Math.max( 1, Math.min( maxResults, rawLimit ) )
			: DEFAULT_LIMIT;
		return { q, mode: m, limit: n };
	}, [ maxResults ] );

	const [ query, setQuery ] = useState( initialFromUrl.q );
	const [ mode, setMode ] = useState( initialFromUrl.mode );
	const [ limit, setLimit ] = useState( initialFromUrl.limit );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ data, setData ] = useState( null );
	const [ hasSearched, setHasSearched ] = useState( false );

	function updateUrl( nextQuery, nextMode, nextLimit ) {
		if ( typeof window === 'undefined' ) {
			return;
		}
		const params = new URLSearchParams( window.location.search );
		if ( nextQuery ) {
			params.set( 'q', nextQuery );
		} else {
			params.delete( 'q' );
		}
		if ( nextMode && nextMode !== DEFAULT_MODE ) {
			params.set( 'mode', nextMode );
		} else {
			params.delete( 'mode' );
		}
		if ( nextLimit && nextLimit !== DEFAULT_LIMIT ) {
			params.set( 'limit', String( nextLimit ) );
		} else {
			params.delete( 'limit' );
		}
		const qs = params.toString();
		const url = window.location.pathname + ( qs ? '?' + qs : '' );
		window.history.replaceState( null, '', url );
	}

	const grouped = useMemo(
		() => ( data ? groupByPost( data.results || [] ) : [] ),
		[ data ]
	);

	async function search( overrides = {} ) {
		const q = ( overrides.query ?? query ).trim();
		if ( ! q ) {
			setError( __( 'Enter a query.', 'wpvdb-smart-search' ) );
			return null;
		}
		const activeMode = overrides.mode ?? mode;
		const activeLimit = overrides.limit ?? limit;
		setError( '' );
		setLoading( true );
		setHasSearched( true );
		updateUrl( q, activeMode, activeLimit );

		try {
			const resp = await fetch( restUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					query: q,
					limit: activeLimit,
					mode: activeMode,
				} ),
			} );
			const body = await resp.json();
			if ( ! resp.ok ) {
				throw new Error( body?.message || 'HTTP ' + resp.status );
			}
			setData( body );
			return body;
		} catch ( err ) {
			setData( null );
			setError( err.message );
			return null;
		} finally {
			setLoading( false );
		}
	}

	async function feelLucky() {
		const body = await search( { limit: 1 } );
		const first = body?.results?.[ 0 ];
		if ( first?.link ) {
			window.location.href = first.link;
		} else if ( ! error ) {
			setError( __( 'No luck — no matching article.', 'wpvdb-smart-search' ) );
		}
	}

	function pickExample( fullQuery ) {
		setQuery( fullQuery );
		setMode( DEFAULT_MODE );
		search( { query: fullQuery, mode: DEFAULT_MODE } );
	}

	// Changing Mode or Results after a search should re-trigger the query.
	function handleModeChange( nextMode ) {
		setMode( nextMode );
		if ( hasSearched && query.trim() ) {
			search( { mode: nextMode } );
		}
	}

	function handleLimitChange( nextLimit ) {
		setLimit( nextLimit );
		if ( hasSearched && query.trim() ) {
			search( { limit: nextLimit } );
		}
	}

	// Auto-run the search if the URL came in pre-filled.
	const didAutoRun = useRef( false );
	useEffect( () => {
		if ( didAutoRun.current ) {
			return;
		}
		didAutoRun.current = true;
		if ( initialFromUrl.q ) {
			search( initialFromUrl );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const wrapPaddingTop = hasSearched
		? 'pt-10'
		: 'pt-[clamp(80px,36vh,320px)]';

	// Title acts as a reset — clears query/mode/limit/results but preserves
	// the chosen language so the user doesn't get kicked back to English.
	const resetHref = ( () => {
		if ( typeof window === 'undefined' ) {
			return '/smart-search';
		}
		const lang = new URLSearchParams( window.location.search ).get( 'lang' );
		return (
			window.location.pathname +
			( lang ? '?lang=' + encodeURIComponent( lang ) : '' )
		);
	} )();

	return (
		<>
			<div className="fixed right-4 top-4 z-50 flex items-center gap-2">
				<LanguageToggle />
				<HelpPopover siteName={ siteName } homeUrl={ homeUrl } />
				<ThemeToggle />
			</div>

			<div
				className={ `mx-auto w-full max-w-[820px] flex-[1_0_auto] px-5 pb-10 transition-[padding-top] duration-[420ms] ease-[cubic-bezier(0.22,0.61,0.36,1)] ${ wrapPaddingTop }` }
			>
				<div className="mb-6 text-center">
					<h1 className="text-[40px] font-semibold tracking-tight leading-none">
						<a
							href={ resetHref }
							className="text-[var(--color-ink)] no-underline hover:no-underline"
						>
							{ __( 'Smart Search', 'wpvdb-smart-search' ) }
						</a>
					</h1>
					<p className="mt-2 text-[13px] text-[var(--color-muted)]">
						{ __( 'Search the archive by meaning', 'wpvdb-smart-search' ) }
					</p>
				</div>

				<Form
					query={ query }
					setQuery={ setQuery }
					mode={ mode }
					setMode={ handleModeChange }
					limit={ limit }
					setLimit={ handleLimitChange }
					maxQuery={ maxQuery }
					maxResults={ maxResults }
					placeholder={ placeholder }
					loading={ loading }
					onSearch={ () => search() }
					onLucky={ feelLucky }
					examples={ examples }
					onPickExample={ pickExample }
				/>

				<Results
					loading={ loading }
					error={ error }
					data={ data }
					grouped={ grouped }
				/>

			</div>

			<footer className="themed-surface shrink-0 border-t border-[var(--color-border)] bg-[var(--color-footer-bg)] text-[12px] text-[var(--color-muted)]">
				<div className="mx-auto flex max-w-[820px] flex-wrap items-center justify-between gap-2 px-5 py-3.5">
					<span>
						{ createInterpolateElement(
							sprintf(
								/* translators: %s: site name shown as a link to the homepage. */
								__( 'Running on %s', 'wpvdb-smart-search' ),
								'<site>' + siteName + '</site>'
							),
							{
								site: (
									<a
										href={ homeUrl }
										target="_blank"
										rel="noopener noreferrer"
									/>
								),
							}
						) }
					</span>
					<span className="flex items-center gap-3">
						<span>
							{ createInterpolateElement(
								__( 'An <a>Automattic</a> speedrun', 'wpvdb-smart-search' ),
								{
									a: (
										<a
											href="https://automattic.com/"
											target="_blank"
											rel="noopener noreferrer"
											className="font-medium"
										/>
									),
								}
							) }
						</span>
						<a
							href="https://github.com/Automattic"
							target="_blank"
							rel="noopener noreferrer"
							aria-label={ __( 'Automattic on GitHub', 'wpvdb-smart-search' ) }
							className="inline-flex items-center"
						>
							<GithubIcon />
						</a>
					</span>
				</div>
				<div className="mx-auto flex max-w-[820px] flex-wrap items-center justify-center gap-x-2 gap-y-1 border-t border-[var(--color-border)] px-5 py-2.5 text-[11px] text-[var(--color-subtle)]">
					<span>
						{ sprintf(
							/* translators: %d: current year. */
							__( '© %d Automattic', 'wpvdb-smart-search' ),
							new Date().getFullYear()
						) }
					</span>
					<span aria-hidden="true">&middot;</span>
					<span>
						{ createInterpolateElement(
							__(
								'Powered by <wpvdb />',
								'wpvdb-smart-search'
							),
							{
								wpvdb: (
									<a
										href="https://github.com/Automattic/wpvdb"
										target="_blank"
										rel="noopener noreferrer"
									>
										<code>wpvdb</code>
									</a>
								),
							}
						) }
					</span>
					<span aria-hidden="true">&middot;</span>
					<span>
						{ sprintf(
							/* translators: %d: requests per minute allowed per IP. */
							__( 'rate-limited %d req/min per IP', 'wpvdb-smart-search' ),
							rateMax
						) }
					</span>
				</div>
			</footer>
		</>
	);
}

function GithubIcon() {
	return (
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="currentColor"
			aria-hidden="true"
		>
			<path d="M12 .5C5.65.5.5 5.65.5 12c0 5.08 3.29 9.39 7.86 10.91.58.11.79-.25.79-.55v-2.15c-3.2.7-3.87-1.37-3.87-1.37-.52-1.32-1.27-1.68-1.27-1.68-1.04-.71.08-.7.08-.7 1.15.08 1.75 1.18 1.75 1.18 1.02 1.75 2.68 1.24 3.33.95.1-.74.4-1.25.72-1.54-2.55-.29-5.24-1.27-5.24-5.67 0-1.25.45-2.27 1.18-3.07-.12-.29-.51-1.45.11-3.02 0 0 .96-.31 3.15 1.17a10.95 10.95 0 0 1 5.74 0c2.18-1.48 3.14-1.17 3.14-1.17.63 1.57.24 2.73.12 3.02.74.8 1.18 1.82 1.18 3.07 0 4.41-2.69 5.38-5.25 5.66.41.35.78 1.05.78 2.11v3.13c0 .3.21.67.79.55C20.22 21.39 23.5 17.08 23.5 12c0-6.35-5.15-11.5-11.5-11.5z" />
		</svg>
	);
}
