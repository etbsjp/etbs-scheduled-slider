/**
 * ETBS Scheduled Slider: スライドの編集画面の動き。
 *
 * - 画像枠のメディア選択（選ぶ・変更・外す）
 * - 表示開始・表示終了の書き戻し（24時間制・曜日・残り時間）、「今すぐ表示する」
 * - 送信前の検査（必須の欠け・確認ダイアログ）
 *
 * 書き戻しの文言は inc/class-etss-display.php と同じ形にそろえる（片方だけ変えると、保存の前後で表記が食い違う）。
 */
( function () {
	'use strict';

	var cfg = window.etssSlide || { nowLocal: 0, savedActive: false, weekdays: [ '日', '月', '火', '水', '木', '金', '土' ] };
	var form = document.getElementById( 'post' );

	if ( ! form ) {
		return;
	}

	var loadedAt = Date.now();
	var startInput = document.getElementById( 'etss-field-start' );
	var endInput = document.getElementById( 'etss-field-end' );

	function pad( number ) {
		return ( number < 10 ? '0' : '' ) + number;
	}

	/**
	 * サイトの時計の「いま」を、UTC の目盛りのミリ秒で返す（getUTC* で読むと、サイトの時計の値になる）。
	 */
	function nowLocalMs() {
		return cfg.nowLocal * 1000 + ( Date.now() - loadedAt );
	}

	/**
	 * datetime-local の値を、UTC の目盛りのミリ秒にする。形が違えば null。
	 */
	function parseLocal( value ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec( value || '' );

		return m ? Date.UTC( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +m[ 4 ], +m[ 5 ] ) : null;
	}

	function toInputValue( ms ) {
		var d = new Date( ms );

		return d.getUTCFullYear() + '-' + pad( d.getUTCMonth() + 1 ) + '-' + pad( d.getUTCDate() ) + 'T' + pad( d.getUTCHours() ) + ':' + pad( d.getUTCMinutes() );
	}

	function formatFull( ms ) {
		var d = new Date( ms );

		return d.getUTCFullYear() + '年' + ( d.getUTCMonth() + 1 ) + '月' + d.getUTCDate() + '日（' + cfg.weekdays[ d.getUTCDay() ] + '）' + pad( d.getUTCHours() ) + ':' + pad( d.getUTCMinutes() );
	}

	function formatDuration( seconds ) {
		var days = Math.floor( seconds / 86400 );
		var hours = Math.floor( ( seconds % 86400 ) / 3600 );
		var minutes = Math.floor( ( seconds % 3600 ) / 60 );

		if ( days > 0 ) {
			return days + '日' + ( hours > 0 ? hours + '時間' : '' );
		}
		if ( hours > 0 ) {
			return hours + '時間' + ( minutes > 0 ? minutes + '分' : '' );
		}
		if ( minutes > 0 ) {
			return minutes + '分';
		}

		return '1分未満';
	}

	function formatRelative( diffSeconds ) {
		if ( Math.abs( diffSeconds ) < 60 ) {
			return diffSeconds > 0 ? 'まもなく' : 'たった今';
		}

		return diffSeconds > 0 ? 'あと' + formatDuration( diffSeconds ) : formatDuration( -diffSeconds ) + '前';
	}

	/**
	 * 表示開始・表示終了の横の書き戻しを更新する。
	 */
	function updateWritebacks() {
		var now = nowLocalMs();
		var startWriteback = document.getElementById( 'etss-start-writeback' );
		var endWriteback = document.getElementById( 'etss-end-writeback' );
		var start = startInput ? parseLocal( startInput.value ) : null;
		var end = endInput ? parseLocal( endInput.value ) : null;

		if ( startWriteback ) {
			startWriteback.textContent = null === start ? '' : '→ ' + formatFull( start ) + ' に表示開始（' + formatRelative( Math.round( ( start - now ) / 1000 ) ) + '）';
		}
		if ( endWriteback ) {
			endWriteback.textContent = null === end
				? '→ 表示終了日時なし（自分で外すまで表示し続けます）'
				: '→ ' + formatFull( end ) + ' に表示終了（' + formatRelative( Math.round( ( end - now ) / 1000 ) ) + '）';
		}
	}

	/**
	 * 欄のエラーを消す。
	 */
	function clearFieldError( key ) {
		var target = document.getElementById( 'title' === key ? 'title' : 'etss-field-' + key );
		var error = document.getElementById( 'etss-error-' + key );

		if ( error ) {
			error.textContent = '';
			error.hidden = true;
		}
		if ( target ) {
			target.removeAttribute( 'aria-invalid' );
		}
	}

	/**
	 * 欄にエラーを出す。aria-invalid と aria-describedby で、欄とエラー文を結ぶ。
	 */
	function setFieldError( key, message ) {
		var target = document.getElementById( 'title' === key ? 'title' : 'etss-field-' + key );
		var error = document.getElementById( 'etss-error-' + key );
		var describedby;

		if ( error ) {
			error.textContent = message;
			error.hidden = false;
		}
		if ( target ) {
			target.setAttribute( 'aria-invalid', 'true' );
			describedby = ( target.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( Boolean );
			if ( error && describedby.indexOf( error.id ) === -1 ) {
				describedby.push( error.id );
				target.setAttribute( 'aria-describedby', describedby.join( ' ' ) );
			}
		}
	}

	/**
	 * 保存に失敗した直後の画面で、サーバが出したエラーを、タイトル欄にも結ぶ（タイトル欄は WordPress の部品なので JS で足す）。
	 */
	function bindServerErrors() {
		Array.prototype.forEach.call( document.querySelectorAll( '.etss-field-error[data-field]' ), function ( error ) {
			var target = document.getElementById( error.getAttribute( 'data-field' ) );

			if ( error.hidden || ! target ) {
				return;
			}
			target.setAttribute( 'aria-invalid', 'true' );
			target.setAttribute( 'aria-describedby', ( ( target.getAttribute( 'aria-describedby' ) || '' ) + ' ' + error.id ).trim() );
		} );
	}

	/**
	 * 送信前に、必須の欠けなどを調べる。
	 *
	 * @return {Array} エラー（`key`・`message`）。
	 */
	function collectErrors() {
		var errors = [];
		var title = document.getElementById( 'title' );
		var slider = document.getElementById( 'etss-field-slider' );
		var url = document.getElementById( 'etss-field-url' );
		var start = startInput ? parseLocal( startInput.value ) : null;
		var end = endInput ? parseLocal( endInput.value ) : null;

		if ( title && '' === title.value.trim() ) {
			errors.push( { key: 'title', message: '代替テキスト（タイトル）を入力してください。' } );
		}

		if ( slider && '' === slider.value ) {
			errors.push( { key: 'slider', message: 'スライダーを選択してください。' } );
		} else if ( slider && slider.value !== slider.getAttribute( 'data-initial' ) ) {
			errors.push( { key: 'slider', message: 'スライダーを変えたときは、先に「下書き保存」してください。画像を選ぶ欄が、そのスライダー用に切り替わります。' } );
		}

		Array.prototype.forEach.call( document.querySelectorAll( '.etss-slot' ), function ( slot ) {
			var input = slot.querySelector( '.etss-slot__input' );

			if ( '1' === slot.getAttribute( 'data-required' ) && input && ( '' === input.value || '0' === input.value ) ) {
				errors.push( { key: 'image-' + slot.getAttribute( 'data-slot' ), message: slot.getAttribute( 'data-required-message' ) } );
			}
		} );

		if ( url ) {
			if ( '' === url.value.trim() ) {
				errors.push( { key: 'url', message: 'リンク先を入力してください。' } );
			} else if ( ! /^(\/(?![/\\])[^\s]*|\/|https?:\/\/[^\s\\]+)$/i.test( url.value.trim() ) ) {
				errors.push( { key: 'url', message: 'リンク先の形式が正しくありません。「/」で始まるサイト内のパスか、「http://」「https://」で始まる URL を入力してください。' } );
			}
		}

		if ( startInput ) {
			if ( null === start ) {
				errors.push( { key: 'start', message: startInput.validity && startInput.validity.badInput ? '表示開始日時の形式が正しくありません。' : '表示開始日時を入力してください。' } );
			}
		}

		if ( endInput ) {
			if ( null === end && endInput.validity && endInput.validity.badInput ) {
				errors.push( { key: 'end', message: '表示終了日時の形式が正しくありません。' } );
			} else if ( null !== end && null !== start && end <= start ) {
				errors.push( { key: 'end', message: '表示終了日時は、表示開始日時より後にしてください。' } );
			}
		}

		return errors;
	}

	/**
	 * エラーを欄ごとと、上の一覧に出し、最初の欄へフォーカスを移す。
	 */
	function showErrors( errors ) {
		var summary = document.getElementById( 'etss-js-summary' );
		var list = summary ? summary.querySelector( 'ul' ) : null;
		var first;

		[ 'title', 'slider', 'url', 'start', 'end' ].forEach( clearFieldError );
		Array.prototype.forEach.call( document.querySelectorAll( '.etss-slot' ), function ( slot ) {
			clearFieldError( 'image-' + slot.getAttribute( 'data-slot' ) );
		} );

		if ( list ) {
			list.innerHTML = '';
		}

		errors.forEach( function ( item ) {
			var id = 'title' === item.key ? 'title' : 'etss-field-' + item.key;
			var li;
			var link;

			setFieldError( item.key, item.message );

			if ( list ) {
				li = document.createElement( 'li' );
				link = document.createElement( 'a' );
				link.href = '#' + id;
				link.className = 'etss-error-link';
				link.textContent = item.message;
				li.appendChild( link );
				list.appendChild( li );
			}
		} );

		if ( summary ) {
			summary.hidden = false;
		}

		first = document.getElementById( 'title' === errors[ 0 ].key ? 'title' : 'etss-field-' + errors[ 0 ].key );
		if ( first ) {
			first.scrollIntoView( { block: 'center' } );
			first.focus();
		}
	}

	/**
	 * 保存すると、その場で表示に出るか（いま表示中でなく、開始が過去で、終了していない）。
	 */
	function willShowImmediately() {
		var now = nowLocalMs();
		var start = startInput ? parseLocal( startInput.value ) : null;
		var end = endInput ? parseLocal( endInput.value ) : null;

		return ! cfg.savedActive && null !== start && start <= now && ( null === end || end > now );
	}

	// 送信前の検査。公開（登録・更新）のボタンで送るときだけ行う。「下書き保存」は止めない。
	var publishClicked = false;
	var publishButton = document.getElementById( 'publish' );

	if ( publishButton ) {
		publishButton.addEventListener( 'click', function () {
			publishClicked = true;
		} );
	}

	// WordPress の送信処理より先に止めるため、捕捉の段階で受ける。
	form.addEventListener( 'submit', function ( event ) {
		var isPublish = event.submitter ? 'publish' === event.submitter.id : publishClicked;
		var errors;

		publishClicked = false;

		if ( ! isPublish ) {
			return;
		}

		errors = collectErrors();
		if ( errors.length ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			showErrors( errors );
			return;
		}

		// 確認のダイアログ（D6）。
		if ( willShowImmediately() && ! window.confirm( 'このスライドは、保存するとすぐスライダーに表示されます。よろしいですか？' ) ) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true );

	// 直した欄は、エラーを消す。
	[ [ 'title', 'title' ], [ 'slider', 'etss-field-slider' ], [ 'url', 'etss-field-url' ], [ 'start', 'etss-field-start' ], [ 'end', 'etss-field-end' ] ].forEach( function ( pair ) {
		var element = document.getElementById( pair[ 1 ] );

		if ( element ) {
			element.addEventListener( 'input', function () {
				clearFieldError( pair[ 0 ] );
			} );
		}
	} );

	// 書き戻し・「今すぐ表示する」・「表示終了日時を空にする」。
	if ( startInput ) {
		startInput.addEventListener( 'input', updateWritebacks );
		startInput.addEventListener( 'change', updateWritebacks );
	}
	if ( endInput ) {
		endInput.addEventListener( 'input', updateWritebacks );
		endInput.addEventListener( 'change', updateWritebacks );
	}

	var nowButton = document.getElementById( 'etss-start-now' );
	if ( nowButton && startInput ) {
		nowButton.addEventListener( 'click', function () {
			startInput.value = toInputValue( nowLocalMs() );
			clearFieldError( 'start' );
			updateWritebacks();
			startInput.focus();
		} );
	}

	var clearButton = document.getElementById( 'etss-end-clear' );
	if ( clearButton && endInput ) {
		clearButton.addEventListener( 'click', function () {
			endInput.value = '';
			clearFieldError( 'end' );
			updateWritebacks();
			endInput.focus();
		} );
	}

	// スライダーを選び直したら、画像枠が変わることを知らせる。
	var sliderSelect = document.getElementById( 'etss-field-slider' );
	var changeNote = document.getElementById( 'etss-slider-change-note' );
	if ( sliderSelect && changeNote ) {
		sliderSelect.addEventListener( 'change', function () {
			changeNote.hidden = '' === sliderSelect.value || sliderSelect.value === sliderSelect.getAttribute( 'data-initial' );
		} );
	}

	// エラー一覧のリンクは、欄へ移動して、フォーカスも移す。
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '.etss-error-link' ) : null;
		var target;

		if ( ! link ) {
			return;
		}

		target = document.getElementById( link.getAttribute( 'href' ).slice( 1 ) );
		if ( target ) {
			event.preventDefault();
			target.scrollIntoView( { block: 'center' } );
			target.focus();
		}
	} );

	/**
	 * 画像枠1つ分の、選ぶ・変更・外す。
	 */
	function initSlot( slot ) {
		var input = slot.querySelector( '.etss-slot__input' );
		var select = slot.querySelector( '.etss-slot__select' );
		var remove = slot.querySelector( '.etss-slot__remove' );
		var preview = slot.querySelector( '.etss-slot__preview' );
		var image = preview ? preview.querySelector( 'img' ) : null;
		var meta = slot.querySelector( '.etss-slot__meta' );
		var key = 'image-' + slot.getAttribute( 'data-slot' );
		var frame = null;

		function render( attachment ) {
			var hasImage = !! attachment;
			var size = attachment && attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : ( attachment ? attachment.url : '' );

			input.value = hasImage ? String( attachment.id ) : '0';
			if ( image ) {
				image.src = hasImage ? size : '';
			}
			if ( preview ) {
				preview.hidden = ! hasImage;
			}
			if ( meta ) {
				meta.hidden = ! hasImage;
				meta.querySelector( '.etss-slot__filename' ).textContent = hasImage ? ( attachment.filename || '' ) : '';
				meta.querySelector( '.etss-slot__size' ).textContent = hasImage && attachment.width && attachment.height ? attachment.width + ' × ' + attachment.height + ' px' : '';
			}
			select.textContent = slot.getAttribute( hasImage ? 'data-label-change' : 'data-label-select' );
			remove.hidden = ! hasImage;

			var missing = slot.querySelector( '.etss-slot__missing' );
			if ( missing ) {
				missing.hidden = true;
			}
			clearFieldError( key );
		}

		select.addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}

			if ( ! frame ) {
				frame = window.wp.media( {
					title: slot.getAttribute( 'data-label-select' ),
					button: { text: '選択' },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					render( frame.state().get( 'selection' ).first().toJSON() );
				} );

				// 開いたとき、いま選んでいる画像を選択済みにする。
				frame.on( 'open', function () {
					var selection = frame.state().get( 'selection' );
					var attachment;

					selection.reset();
					if ( input.value && '0' !== input.value ) {
						attachment = window.wp.media.attachment( input.value );
						attachment.fetch();
						selection.add( attachment );
					}
				} );
			}

			frame.open();
		} );

		remove.addEventListener( 'click', function () {
			render( null );
			select.focus();
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.etss-slot' ), initSlot );

	bindServerErrors();
	updateWritebacks();
}() );
