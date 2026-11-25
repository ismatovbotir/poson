/**
 * Plan 42: which clicks on a data-lead-modal link open the modal, without
 * any browser dependency (tests/Feature/Site/LeadModalClickJsTest.php runs
 * it with node when available). Only a plain left click on a link that
 * opens in the same tab; modifier and middle clicks, target="_blank" and
 * download links keep the browser's behaviour.
 */
export function opensLeadModal(event, link) {
    if (!event || !link || event.defaultPrevented) {
        return false;
    }

    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false;
    }

    const target = typeof link.getAttribute === 'function' ? link.getAttribute('target') : null;
    const download = typeof link.hasAttribute === 'function' ? link.hasAttribute('download') : false;

    return !download && (target === null || target === '' || target === '_self');
}
