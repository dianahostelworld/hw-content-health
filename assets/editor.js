/**
 * Content health panel in the post editor: similar articles that already
 * exist, links to add, and an existing article's own search figures.
 *
 * Plain JavaScript on the editor's own libraries, so it needs no build step.
 */
( function ( wp ) {
	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var DocumentPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var PrePublishPanel = ( wp.editor && wp.editor.PluginPrePublishPanel ) || ( wp.editPost && wp.editPost.PluginPrePublishPanel );
	var Sidebar = ( wp.editor && wp.editor.PluginSidebar ) || ( wp.editPost && wp.editPost.PluginSidebar );
	var SidebarMenuItem = ( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem );
	var config = window.hwchEditor || {};

	if ( ! DocumentPanel ) {
		return;
	}

	// One shared result for both panels, so the check runs once per change.
	var state = { key: '', data: null, loading: false, error: '' };
	var listeners = [];

	function notify() {
		listeners.forEach( function ( fn ) {
			fn( Object.assign( {}, state ) );
		} );
	}

	function run( input, force ) {
		var key = JSON.stringify( [ input.id, input.title, input.tags ] );

		if ( ! force && ( key === state.key ) ) {
			return;
		}

		state.key = key;
		state.loading = true;
		state.error = '';
		notify();

		wp.apiFetch( {
			path: '/hwch/v1/check',
			method: 'POST',
			data: {
				post_id: input.id || 0,
				title: input.title || '',
				tags: input.tags || [],
				content: wp.data.select( 'core/editor' ).getEditedPostContent() || '',
			},
		} ).then( function ( data ) {
			if ( state.key !== key ) {
				return;
			}
			state.data = data;
			state.loading = false;
			notify();
		} ).catch( function ( err ) {
			if ( state.key !== key ) {
				return;
			}
			state.loading = false;
			state.error = ( err && err.message ) || 'The check could not run.';
			notify();
		} );
	}

	function useCheck() {
		var input = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				id: editor.getCurrentPostId(),
				title: editor.getEditedPostAttribute( 'title' ) || '',
				tags: editor.getEditedPostAttribute( 'tags' ) || [],
			};
		}, [] );

		var local = useState( Object.assign( {}, state ) );
		var view = local[ 0 ];
		var setView = local[ 1 ];

		useEffect( function () {
			listeners.push( setView );
			return function () {
				listeners = listeners.filter( function ( fn ) {
					return fn !== setView;
				} );
			};
		}, [] );

		// Wait until typing pauses before checking.
		useEffect( function () {
			var timer = setTimeout( function () {
				run( input, false );
			}, 1200 );
			return function () {
				clearTimeout( timer );
			};
		}, [ input.id, input.title, JSON.stringify( input.tags ) ] );

		return {
			view: view,
			again: function () {
				run( input, true );
			},
		};
	}

	function number( n ) {
		return Number( n || 0 ).toLocaleString();
	}

	function link( item ) {
		var href = item.edit || item.url;
		return el( 'a', { href: href, target: '_blank', rel: 'noopener noreferrer' }, item.title );
	}

	function section( title, children ) {
		return el( 'div', { className: 'hwch-ed-section' }, el( 'h3', { className: 'hwch-ed-heading' }, title ), children );
	}

	function similarReason( item ) {
		var bits = [];
		if ( item.pair !== null && item.pair !== undefined ) {
			bits.push( 'already flagged in Overlapping articles' );
		}
		if ( item.titleScore ) {
			bits.push( 'titles ' + item.titleScore + '% alike' );
		}
		if ( item.impressions ) {
			bits.push( number( item.impressions ) + ' impressions from searches like this title' );
		}
		return bits.join( ', ' );
	}

	function similarList( items ) {
		return el(
			'ul',
			{ className: 'hwch-ed-list' },
			items.map( function ( item ) {
				return el(
					'li',
					{ key: item.id, className: item.strong ? 'hwch-ed-strong' : '' },
					link( item ),
					el( 'div', { className: 'hwch-ed-muted' }, similarReason( item ) + ( item.clicks ? '. ' + number( item.clicks ) + ' clicks.' : '.' ) ),
					item.queries && item.queries.length
						? el(
							'div',
							{ className: 'hwch-ed-muted' },
							'For example: ' + item.queries.map( function ( q ) {
								return '“' + q.query + '” (position ' + Number( q.position ).toFixed( 1 ) + ')';
							} ).join( ', ' )
						)
						: null
				);
			} )
		);
	}

	function linkList( items, empty ) {
		if ( ! items.length ) {
			return el( 'p', { className: 'hwch-ed-muted' }, empty );
		}
		return el(
			'ul',
			{ className: 'hwch-ed-list' },
			items.map( function ( item ) {
				return el(
					'li',
					{ key: item.url },
					link( item ),
					item.hub ? el( 'span', { className: 'hwch-ed-tag' }, 'Destination hub' ) : null,
					! item.hub && item.clicks ? el( 'span', { className: 'hwch-ed-muted' }, ' · ' + number( item.clicks ) + ' clicks' ) : null
				);
			} )
		);
	}

	function Stats( props ) {
		var s = props.stats;
		var trend = s.trend === null || s.trend === undefined ? '–' : ( s.trend > 0 ? '+' : '' ) + s.trend + '%';

		return section(
			'Search performance',
			el(
				Fragment,
				null,
				el(
					'div',
					{ className: 'hwch-ed-kpis' },
					el( 'div', null, el( 'strong', null, number( s.clicks ) ), el( 'span', null, 'clicks' ) ),
					el( 'div', null, el( 'strong', null, number( s.impressions ) ), el( 'span', null, 'impressions' ) ),
					el( 'div', null, el( 'strong', null, s.position ? Number( s.position ).toFixed( 1 ) : '–' ), el( 'span', null, 'avg. position' ) ),
					el( 'div', null, el( 'strong', { className: s.trend < 0 ? 'hwch-ed-down' : 'hwch-ed-up' }, trend ), el( 'span', null, 'clicks, last 90 days' ) )
				),
				s.range ? el( 'p', { className: 'hwch-ed-muted' }, s.range[ 0 ] + ' to ' + s.range[ 1 ] + '.' ) : null,
				s.season
					? el(
						'p',
						{ className: 'hwch-ed-season' },
						'Seasonal: ' + s.season.label + ', peaking in ' + s.season.peak + ' at ' + s.season.strength + '× an average month.' + ( s.season.due ? ' Refresh by ' + s.season.due + '.' : ' In season now.' )
					)
					: null,
				s.queries && s.queries.length
					? el(
						'table',
						{ className: 'hwch-ed-table' },
						el( 'thead', null, el( 'tr', null, el( 'th', null, 'Top searches' ), el( 'th', null, 'Clicks' ), el( 'th', null, 'Pos.' ) ) ),
						el(
							'tbody',
							null,
							s.queries.map( function ( q ) {
								return el( 'tr', { key: q.query }, el( 'td', null, q.query ), el( 'td', null, number( q.clicks ) ), el( 'td', null, Number( q.position ).toFixed( 1 ) ) );
							} )
						)
					)
					: null,
				s.questions && s.questions.length
					? el(
						'div',
						{ className: 'hwch-ed-questions' },
						el( 'p', { className: 'hwch-ed-label' }, 'Questions it appears for, not yet in a heading or FAQ:' ),
						el(
							'ul',
							{ className: 'hwch-ed-list' },
							s.questions.map( function ( q ) {
								return el( 'li', { key: q.query }, q.query, el( 'span', { className: 'hwch-ed-muted' }, ' · ' + number( q.impressions ) + ' impressions' ) );
							} )
						)
					)
					: null
			)
		);
	}

	function Body( props ) {
		var view = props.view;
		var data = view.data;

		if ( ! config.hasData ) {
			return el( 'p', { className: 'hwch-ed-muted' }, 'Content health has no Search Console data yet. Run the first sync in Content health › Settings.' );
		}

		if ( view.loading && ! data ) {
			return el( 'p', null, el( Spinner, null ), ' Checking…' );
		}

		if ( view.error ) {
			return el( 'p', { className: 'hwch-ed-error' }, view.error );
		}

		if ( ! data ) {
			return el( 'p', { className: 'hwch-ed-muted' }, 'Type a title to check for similar articles.' );
		}

		var parts = [];

		if ( data.plan ) {
			parts.push(
				section(
					'From the content plan',
					el(
						Fragment,
						null,
						data.plan.topic ? el( 'p', { className: 'hwch-ed-label' }, 'Topic: ' + data.plan.topic + ( data.plan.due ? ' · publish by ' + data.plan.due : '' ) ) : ( data.plan.due ? el( 'p', { className: 'hwch-ed-label' }, 'Publish by ' + data.plan.due ) : null ),
						data.plan.notes ? el( 'p', null, data.plan.notes ) : null,
						data.plan.searches.length
							? el(
								Fragment,
								null,
								el( 'p', { className: 'hwch-ed-label' }, 'Searches to cover (with no article before this one):' ),
								el(
									'ul',
									{ className: 'hwch-ed-list' },
									data.plan.searches.map( function ( q ) {
										return el( 'li', { key: q.query }, q.query, el( 'span', { className: 'hwch-ed-muted' }, ' · ' + number( q.impressions ) + ' impressions' ) );
									} )
								)
							)
							: null
					)
				)
			);
		}

		if ( data.stats ) {
			parts.push( el( Stats, { key: 'stats', stats: data.stats } ) );
		}

		if ( ! data.english ) {
			parts.push( el( 'p', { key: 'lang', className: 'hwch-ed-muted' }, 'Similar articles and link suggestions are checked for English articles only, like the Overlapping articles and Internal links reports.' ) );
		} else {
			parts.push(
				section(
					'Similar articles already published',
					data.similar.length
						? similarList( data.similar )
						: el( 'p', { className: 'hwch-ed-muted' }, data.wordCount < 2 ? 'Add a few more words to the title to check.' : 'None found among published English articles.' )
				)
			);

			if ( ! data.places.length ) {
				parts.push( section( 'Links', el( 'p', { className: 'hwch-ed-muted' }, 'Add a destination tag, or name the destination in the title, to get link suggestions.' ) ) );
			} else {
				parts.push( section( 'Link to these from this article', linkList( data.linkTo, 'Already links to the hub and the main articles about ' + data.places.join( ', ' ) + '.' ) ) );
				parts.push( section( 'Link to this article from these', linkList( data.linkFrom, 'Every other article about ' + data.places.join( ', ' ) + ' links here already.' ) ) );
			}
		}

		if ( data.briefUrl ) {
			parts.push( el( 'p', { key: 'brief' }, el( 'a', { href: data.briefUrl, target: '_blank', rel: 'noopener noreferrer' }, 'Open the refresh brief' ) ) );
		}

		parts.push(
			el(
				'p',
				{ key: 'again', className: 'hwch-ed-again' },
				el( Button, { variant: 'secondary', isSmall: true, onClick: props.again, disabled: view.loading }, view.loading ? 'Checking…' : 'Check again' )
			)
		);

		return el( Fragment, null, parts.map( function ( part, i ) {
			return el( Fragment, { key: i }, part );
		} ) );
	}

	function Panel() {
		var check = useCheck();
		return el( DocumentPanel, { name: 'hwch-content-health', title: 'Content health', className: 'hwch-ed' }, el( Body, check ) );
	}

	// Its own sidebar, opened from a chart icon in the editor's top bar, so
	// the check is easy to find without hunting through the Post settings.
	function OwnSidebar() {
		var check = useCheck();
		return el(
			Fragment,
			null,
			SidebarMenuItem ? el( SidebarMenuItem, { target: 'hwch-sidebar', icon: 'chart-line' }, 'Content health' ) : null,
			el( Sidebar, { name: 'hwch-sidebar', title: 'Content health', icon: 'chart-line', className: 'hwch-ed' }, el( 'div', { className: 'hwch-ed-sidebar' }, el( Body, check ) ) )
		);
	}

	function PrePublish() {
		var check = useCheck();
		var data = check.view.data;
		var strong = data && data.similar ? data.similar.filter( function ( item ) {
			return item.strong;
		} ) : [];

		if ( ! strong.length ) {
			return null;
		}

		return el(
			PrePublishPanel,
			{ title: 'Similar articles already exist', initialOpen: true, className: 'hwch-ed hwch-ed-prepublish' },
			el( 'p', null, 'These published articles target the same topic. Publishing another may split clicks between them. Consider updating one of these instead, or make the new angle clear in the title.' ),
			similarList( strong )
		);
	}

	wp.plugins.registerPlugin( 'hwch-content-health', {
		render: function () {
			return el( Fragment, null, el( Panel, null ), Sidebar ? el( OwnSidebar, null ) : null, PrePublishPanel ? el( PrePublish, null ) : null );
		},
		icon: 'chart-line',
	} );
} )( window.wp );
