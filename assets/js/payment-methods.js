(function () {
    if (!window.wc || !window.wc.wcSettings || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
        return;
    }

    const { createElement } = window.wp.element;
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const settingsFor = (name) => window.wc.wcSettings.getSetting(name + '_data', {});

    [
        { name: 'wfpms_card', fallback: 'Оплата карткою' },
        { name: 'wfpms_iban', fallback: 'Переказ на рахунок' },
    ].forEach((method) => {
        const settings = settingsFor(method.name);
        registerPaymentMethod({
            name: method.name,
            label: createElement('span', null, settings.title || method.fallback),
            content: createElement('div', null, settings.description || ''),
            edit: createElement('div', null, settings.description || ''),
            canMakePayment: () => true,
            ariaLabel: settings.title || method.fallback,
            supports: { features: settings.supports || ['products'] },
        });
    });
})();
