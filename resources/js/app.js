import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.data('adminLayout', () => ({
    collapsed: false,
    mobile: false,
    desktop: window.matchMedia('(min-width: 1024px)').matches,
    userMenu: false,
    init() {
        try { this.collapsed = localStorage.getItem('farm-sidebar-collapsed') === 'true'; } catch (_) { /* Storage may be unavailable. */ }
        this.$watch('collapsed', value => {
            try { localStorage.setItem('farm-sidebar-collapsed', String(value)); } catch (_) { /* Navigation still works without storage. */ }
        });
        this.$watch('mobile', value => {
            if (value) this.$nextTick(() => this.$refs.closeNavigation.focus());
            else this.$refs.openNavigation?.focus();
        });
    },
    trapNavigation(event) {
        if (this.desktop || !this.mobile || event.key !== 'Tab') return;
        const links = [...this.$refs.sidebar.querySelectorAll('a[href], button')].filter(el => el.offsetParent !== null);
        const first = links[0];
        const last = links[links.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    },
}));
Alpine.data('purchaseForm', (rows, materials, units) => ({
    lines: rows.map((line, index) => ({ ...line, key: index })),
    nextKey: rows.length,
    materials,
    units,
    addLine() {
        if (this.lines.length < 30) this.lines.push({ key: this.nextKey++, material_id: '', material_unit_id: '', quantity: '1', unit_price: '0', discount: '0' });
    },
    compatibleUnits(line) {
        const material = this.materials.find(item => item.id == line.material_id);
        return material ? this.units.filter(unit => unit.dimension === material.dimension && (unit.dimension !== 'custom' || unit.id === material.unit_id)) : [];
    },
    lineTotal(line) {
        return Math.max(0, Math.round((Number(line.quantity || 0) * Number(line.unit_price || 0) + Number.EPSILON) * 100) / 100 - Number(line.discount || 0));
    },
}));
Alpine.start();

if (document.getElementById('analytics-chart')) {
    import('./reports').catch(() => { document.getElementById('analytics-chart-error').hidden = false; });
}

const label = document.querySelector('[data-qr]');
if (label) {
    import('qrcode').then(({ default: QRCode }) => QRCode.toCanvas(label, label.dataset.qr, { width: 200, margin: 2, errorCorrectionLevel: 'M' }))
        .catch(() => { document.querySelector('[data-qr-error]').hidden = false; });
}
