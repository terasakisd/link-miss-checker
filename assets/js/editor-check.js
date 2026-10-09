/**
 * 記事の「更新/公開」ボタンを横取りして、保存前にリンクチェックを実行する。
 * エラーがあれば一覧モーダルを表示して保存を止める。
 * ブロックエディタ・クラシックエディタ両対応。
 */
(function () {
	'use strict';

	if ( typeof lmcConfig === 'undefined' ) {
		return;
	}

	var passed = false;      // チェック通過済み（次のクリックは素通し）
	var checking = false;    // チェック実行中

	/* ---------- 本文の取得 ---------- */

	function getContent() {
		// ブロックエディタ
		if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
			var content = wp.data.select( 'core/editor' ).getEditedPostContent();
			if ( typeof content === 'string' ) {
				return content;
			}
		}
		// クラシックエディタ（ビジュアル）
		if ( window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ) {
			return tinymce.get( 'content' ).getContent();
		}
		// クラシックエディタ（テキスト）
		var textarea = document.getElementById( 'content' );
		return textarea ? textarea.value : '';
	}

	/* ---------- モーダル・オーバーレイ ---------- */

	function removeOverlay() {
		var el = document.getElementById( 'lmc-overlay' );
		if ( el ) {
			el.remove();
		}
	}

	function showSpinner() {
		removeOverlay();
		var overlay = document.createElement( 'div' );
		overlay.id = 'lmc-overlay';
		overlay.innerHTML =
			'<div class="lmc-modal lmc-modal-spinner">' +
			'<div class="lmc-titlebar"><span class="lmc-titlebar-text">リンクチェック</span></div>' +
			'<div class="lmc-body lmc-body-spinner">' +
			'<span class="lmc-spinner"></span>' +
			'<div>' +
			'<p class="lmc-spinner-title">リンクをチェックしています…</p>' +
			'<p class="lmc-spinner-sub">リンク数が多い場合は少し時間がかかります。</p>' +
			'</div>' +
			'</div>' +
			'</div>';
		document.body.appendChild( overlay );
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str || '';
		return div.innerHTML;
	}

	function showResults( data, retryButton ) {
		removeOverlay();

		var overlay = document.createElement( 'div' );
		overlay.id = 'lmc-overlay';

		// 重大度ごとの件数を集計。
		var errorCount = 0;
		var warnCount  = 0;
		data.issues.forEach( function ( issue ) {
			issue.problems.forEach( function ( p ) {
				if ( p.severity === 'error' ) {
					errorCount++;
				} else {
					warnCount++;
				}
			} );
		} );

		var rows = data.issues.map( function ( issue ) {
			var isError = issue.problems.some( function ( p ) {
				return p.severity === 'error';
			} );

			var problems = issue.problems.map( function ( p ) {
				return '<li class="lmc-problem lmc-problem-' + p.severity + '">' +
					escapeHtml( p.message ) + '</li>';
			} ).join( '' );

			return '<div class="lmc-issue ' + ( isError ? 'lmc-sev-error' : 'lmc-sev-warning' ) + '">' +
				'<div class="lmc-issue-top">' +
				'<span class="lmc-badge ' + ( isError ? 'lmc-badge-error' : 'lmc-badge-warning' ) + '">' +
				( isError ? 'エラー' : '警告' ) + '</span>' +
				'<a class="lmc-issue-url" href="' + escapeHtml( issue.url ) + '" target="_blank" rel="noopener noreferrer" title="リンク先を新しいタブで開く">' +
				escapeHtml( issue.url ) + '<span class="lmc-ext">↗</span></a>' +
				'</div>' +
				( issue.anchor_text ? '<div class="lmc-issue-anchor">リンクテキスト: ' + escapeHtml( issue.anchor_text ) + '</div>' : '' ) +
				'<ul class="lmc-problems">' + problems + '</ul>' +
				'</div>';
		} ).join( '' );

		var state, title, sub;
		if ( data.issues.length === 0 ) {
			state = 'ok';
			title = 'チェック完了';
			sub   = data.links + ' 件のリンクを確認しました。問題はありません。そのまま更新します。';
		} else if ( data.has_error ) {
			state = 'error';
			title = 'リンクに問題が見つかりました';
			sub   = '全 ' + data.links + ' リンクを確認しました。修正してから更新してください。';
		} else {
			state = 'warning';
			title = '確認が必要な警告があります';
			sub   = '全 ' + data.links + ' リンクを確認しました。内容を確認のうえ更新してください。';
		}

		var icons = {
			ok: '<svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="14" fill="#34a853"/><path d="M9.5 16.5l4.5 4.5 8.5-9" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			error: '<svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="14" fill="#d93025"/><rect x="14.4" y="8" width="3.2" height="11" rx="1.6" fill="#fff"/><circle cx="16" cy="23" r="2" fill="#fff"/></svg>',
			warning: '<svg viewBox="0 0 32 32"><path d="M16 3L30 28H2z" fill="#f9ab00"/><rect x="14.5" y="12" width="3" height="8" rx="1.5" fill="#fff"/><circle cx="16" cy="24" r="1.8" fill="#fff"/></svg>'
		};

		var summary = [];
		if ( errorCount > 0 ) {
			summary.push( '<span class="lmc-count-error">エラー ' + errorCount + ' 件</span>' );
		}
		if ( warnCount > 0 ) {
			summary.push( '<span class="lmc-count-warning">警告 ' + warnCount + ' 件</span>' );
		}
		summary.push( '確認したリンク ' + data.links + ' 件' );

		var footer = '';
		if ( data.issues.length > 0 ) {
			footer =
				'<div class="lmc-footer">' +
				'<label class="lmc-check"><input type="checkbox" id="lmc-force-check"> このまま更新する</label>' +
				'<span class="lmc-footer-buttons">' +
				'<button type="button" class="lmc-btn lmc-btn-primary" id="lmc-close">閉じて修正する</button>' +
				'<button type="button" class="lmc-btn" id="lmc-force" disabled>更新</button>' +
				'</span>' +
				'</div>';
		}

		overlay.innerHTML =
			'<div class="lmc-modal lmc-state-' + state + '" role="dialog" aria-modal="true" aria-label="' + title + '">' +
			'<div class="lmc-titlebar">' +
			'<span class="lmc-titlebar-text">リンクチェック</span>' +
			'<button type="button" class="lmc-x" id="lmc-x" aria-label="閉じる">&#10005;</button>' +
			'</div>' +
			'<div class="lmc-body">' +
			'<div class="lmc-header">' +
			'<span class="lmc-icon">' + icons[ state ] + '</span>' +
			'<div class="lmc-header-text">' +
			'<h2>' + title + '</h2>' +
			'<p>' + sub + '</p>' +
			'<p class="lmc-summary">' + summary.join( '、' ) + '</p>' +
			'</div>' +
			'</div>' +
			( rows ? '<div class="lmc-issues">' + rows + '</div>' : '' ) +
			'</div>' +
			footer +
			'</div>';

		document.body.appendChild( overlay );

		var close = function () {
			removeOverlay();
		};
		var closeBtn = document.getElementById( 'lmc-close' );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', close );
		}
		var xBtn = document.getElementById( 'lmc-x' );
		if ( xBtn ) {
			xBtn.addEventListener( 'click', close );
		}

		var forceBtn   = document.getElementById( 'lmc-force' );
		var forceCheck = document.getElementById( 'lmc-force-check' );
		if ( forceBtn && forceCheck ) {
			// チェックを入れたときだけ「更新」ボタンを押せるようにする。
			forceCheck.addEventListener( 'change', function () {
				forceBtn.disabled = ! forceCheck.checked;
			} );
			forceBtn.addEventListener( 'click', function () {
				if ( ! forceCheck.checked ) {
					return;
				}
				removeOverlay();
				proceedSave( retryButton );
			} );
		}

		// 問題なし → 自動で保存続行。
		if ( data.issues.length === 0 ) {
			setTimeout( function () {
				removeOverlay();
				proceedSave( retryButton );
			}, 900 );
		}
	}

	function showCheckError( message, retryButton ) {
		removeOverlay();
		var overlay = document.createElement( 'div' );
		overlay.id = 'lmc-overlay';
		overlay.innerHTML =
			'<div class="lmc-modal lmc-state-error" role="dialog" aria-modal="true">' +
			'<div class="lmc-titlebar"><span class="lmc-titlebar-text">リンクチェック</span></div>' +
			'<div class="lmc-body">' +
			'<div class="lmc-header">' +
			'<span class="lmc-icon"><svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="14" fill="#d93025"/><rect x="14.4" y="8" width="3.2" height="11" rx="1.6" fill="#fff"/><circle cx="16" cy="23" r="2" fill="#fff"/></svg></span>' +
			'<div class="lmc-header-text">' +
			'<h2>チェックを実行できませんでした</h2>' +
			'<p>' + escapeHtml( message ) + '</p>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'<div class="lmc-footer">' +
			'<label class="lmc-check"><input type="checkbox" id="lmc-force-check"> チェックせずに更新する</label>' +
			'<span class="lmc-footer-buttons">' +
			'<button type="button" class="lmc-btn lmc-btn-primary" id="lmc-close">閉じる</button>' +
			'<button type="button" class="lmc-btn" id="lmc-force" disabled>更新</button>' +
			'</span>' +
			'</div></div>';
		document.body.appendChild( overlay );

		document.getElementById( 'lmc-close' ).addEventListener( 'click', removeOverlay );
		var errForce = document.getElementById( 'lmc-force' );
		var errCheck = document.getElementById( 'lmc-force-check' );
		errCheck.addEventListener( 'change', function () {
			errForce.disabled = ! errCheck.checked;
		} );
		errForce.addEventListener( 'click', function () {
			if ( ! errCheck.checked ) {
				return;
			}
			removeOverlay();
			proceedSave( retryButton );
		} );
	}

	/* ---------- 保存の続行 ---------- */

	function proceedSave( button ) {
		passed = true;
		if ( button && document.body.contains( button ) ) {
			button.click();
		} else if ( window.wp && wp.data && wp.data.dispatch( 'core/editor' ) ) {
			wp.data.dispatch( 'core/editor' ).savePost();
		}
		// 少し後にフラグを戻す（次回の更新時は再チェック）。
		setTimeout( function () {
			passed = false;
		}, 3000 );
	}

	/* ---------- チェック実行 ---------- */

	function runCheck( retryButton ) {
		if ( checking ) {
			return;
		}
		checking = true;
		showSpinner();

		fetch( lmcConfig.restUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': lmcConfig.nonce
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				post_id: lmcConfig.postId,
				content: getContent()
			} )
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'サーバーエラー（HTTP ' + res.status + '）' );
				}
				return res.json();
			} )
			.then( function ( data ) {
				checking = false;
				showResults( data, retryButton );
			} )
			.catch( function ( err ) {
				checking = false;
				showCheckError( err.message, retryButton );
			} );
	}

	/* ---------- クリックの横取り ---------- */

	function isSaveButton( el ) {
		if ( ! el ) {
			return null;
		}
		// ブロックエディタの「更新/公開」ボタン
		var btn = el.closest( '.editor-post-publish-button, .editor-post-publish-button__button' );
		if ( btn ) {
			return btn;
		}
		// クラシックエディタの「更新/公開」ボタン
		btn = el.closest( '#publish' );
		return btn;
	}

	document.addEventListener( 'click', function ( event ) {
		if ( ! lmcConfig.blockOnError ) {
			return;
		}
		if ( passed || checking ) {
			return;
		}

		var button = isSaveButton( event.target );
		if ( ! button ) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		runCheck( button );
	}, true ); // capture段階で横取り

	// クラシックエディタ: タイトル欄でのEnterキー等、ボタンを経由しない
	// フォーム送信もチェックを通す。
	document.addEventListener( 'submit', function ( event ) {
		if ( ! lmcConfig.blockOnError ) {
			return;
		}
		if ( passed || checking ) {
			return;
		}

		var form = event.target;
		if ( ! form || 'post' !== form.id ) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		runCheck( document.getElementById( 'publish' ) );
	}, true );

} )();
