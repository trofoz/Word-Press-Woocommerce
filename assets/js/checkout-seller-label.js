(function () {
    const wc = window.wc || {};
    const wp = window.wp || {};
    const blocksCheckout = wc.blocksCheckout || {};
    const settings = wc.wcSettings;

    if (!wp.plugins || !wp.element || !blocksCheckout.ExperimentalOrderMeta || !settings) {
        return;
    }

    const sellerName = settings.getSetting('wfpms-checkout-seller_data', {}).sellerName || '';
    if (!sellerName) {
        return;
    }

    const { createElement } = wp.element;
    const { ExperimentalOrderMeta } = blocksCheckout;

    wp.plugins.registerPlugin('wfpms-checkout-seller', {
        render: () => createElement(
            ExperimentalOrderMeta,
            null,
            createElement(
                'p',
                { className: 'wfpms-checkout-seller' },
                createElement('strong', null, 'Продавець: '),
                sellerName
            )
        ),
        scope: 'woocommerce-checkout',
    });
})();
