/**
 * ブロック `etss/slider` の編集画面。
 *
 * 編集画面ではスライダーを動かさない。選んだスライダーの、表示中の枚数・次の切り替え・各画面へのリンクを出す。
 * 描画（フロント）は PHP の ETSS_Block::render() が行う（動的ブロックなので save は null）。
 * ビルドは使わず、JSX を書かずにそのまま読み込む。依存は index.asset.php に書く。
 * スライダーの一覧と状況は、PHP が window.etssSliderBlock に渡す（ETSS_Block::add_editor_data()）。
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var Placeholder = wp.components.Placeholder;
	var SelectControl = wp.components.SelectControl;
	var Notice = wp.components.Notice;
	var ExternalLink = wp.components.ExternalLink;

	/**
	 * 編集画面のデータを返す。PHP が渡せなかったときも落ちないようにする。
	 *
	 * @return {Object} canManage・vkAvailable・sliders・newUrl。
	 */
	function getData() {
		return window.etssSliderBlock || { canManage: false, vkAvailable: true, sliders: [], newUrl: '' };
	}

	/**
	 * スライダーの id から、状況を探す。
	 *
	 * @param {Array}  sliders スライダーの配列。
	 * @param {number} id      スライダーの投稿 ID。
	 * @return {Object|null} 見つかった状況。
	 */
	function findSlider( sliders, id ) {
		for ( var i = 0; i < sliders.length; i++ ) {
			if ( sliders[ i ].id === id ) {
				return sliders[ i ];
			}
		}
		return null;
	}

	/**
	 * 選んだスライダーの状況（枚数・次の切り替え・リンク）を作る。
	 *
	 * @param {Object|null} slider 状況。無ければ null。
	 * @param {number}      id     選んでいるスライダーの投稿 ID。
	 * @return {Object} 要素。
	 */
	function renderStatus( slider, id ) {
		if ( ! slider ) {
			return el(
				Notice,
				{ status: 'warning', isDismissible: false },
				'選んだスライダー（#' + id + '）が見つかりません。別のスライダーを選び直してください。'
			);
		}

		return el(
			Fragment,
			null,
			el(
				'p',
				null,
				el( 'strong', null, '表示中 ' + slider.active + ' 枚' ),
				'／' + slider.next
			),
			slider.active === 0
				? el(
						Notice,
						{ status: 'warning', isDismissible: false },
						'いま表示するスライドがありません。公開したページには何も出ません。スライドの一覧で、表示中のスライドを確かめてください。'
				  )
				: null,
			el( 'p', null, el( ExternalLink, { href: slider.listUrl }, 'このスライダーのスライドの一覧を開く' ) ),
			slider.editUrl ? el( 'p', null, el( ExternalLink, { href: slider.editUrl }, 'スライダーの設定を開く' ) ) : null
		);
	}

	wp.blocks.registerBlockType( 'etss/slider', {
		edit: function ( props ) {
			var data = getData();
			var sliderId = props.attributes.sliderId || 0;
			var blockProps = wp.blockEditor.useBlockProps();
			var options = [ { label: 'スライダーを選んでください', value: 0 } ].concat(
				data.sliders.map( function ( slider ) {
					return { label: slider.label, value: slider.id };
				} )
			);
			var children = [];

			if ( ! data.vkAvailable ) {
				children.push(
					el(
						Notice,
						{ key: 'vk', status: 'warning', isDismissible: false },
						'VK Blocks のスライダーが使えないため、公開したページには何も表示されません。VK Blocks（無料版でよい）を有効にしてください。'
					)
				);
			}

			if ( ! data.canManage ) {
				children.push( el( 'p', { key: 'cap' }, 'スライダーを選べるのは、編集者以上の権限をもつ人です。' ) );
			} else if ( data.sliders.length === 0 ) {
				children.push(
					el(
						'p',
						{ key: 'new' },
						'スライダーがまだありません。',
						el( ExternalLink, { href: data.newUrl }, 'スライダーを作成する' )
					)
				);
			} else {
				children.push(
					el( SelectControl, {
						key: 'select',
						label: 'スライダー',
						value: sliderId,
						options: options,
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							props.setAttributes( { sliderId: parseInt( value, 10 ) || 0 } );
						},
					} )
				);

				if ( sliderId > 0 ) {
					children.push( el( Fragment, { key: 'status' }, renderStatus( findSlider( data.sliders, sliderId ), sliderId ) ) );
				}
			}

			return el(
				'div',
				blockProps,
				el(
					Placeholder,
					{
						icon: 'images-alt2',
						label: '予約スライダー',
						instructions: '編集画面ではスライダーは動きません。公開したページで、表示開始・表示終了の日時どおりに表示されます。',
					},
					el( 'div', { style: { width: '100%' } }, children )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
