import { mount } from '@vue/test-utils';
import { Modal } from 'bootstrap';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import Alert from './Alert.vue';

let wrapper;
let container;

function mountAlert(props = {}) {
    const alert = { type: 'confirmation', message: 'Confirm?', ...props };
    container = document.createElement('div');
    document.body.appendChild(container);
    wrapper = mount(Alert, {
        attachTo: container,
        props: alert,
        global: { mixins: [{ data: () => ({ alert }) }] },
    });

    return alert;
}

afterEach(() => {
    wrapper?.unmount();
    container?.remove();
    vi.useRealTimers();
});

it.each([
    ['Yes', 'confirmationProceed'],
    ['Cancel', 'confirmationCancel'],
])('calls the %s action and clears the alert', async (label, callback) => {
    const action = vi.fn();
    const alert = mountAlert({ [callback]: action });

    expect(document.activeElement).toBe(wrapper.element);
    expect(document.body.classList.contains('modal-open')).toBe(true);
    await wrapper
        .findAll('button')
        .find((button) => button.text() === label)
        .trigger('click');

    expect(action).toHaveBeenCalledOnce();
    expect(alert.type).toBeNull();
    expect(alert.message).toBe('');
    expect(document.body.classList.contains('modal-open')).toBe(false);
    expect(document.body.style.overflow).toBe('');
    expect(document.querySelector('.modal-backdrop')).toBeNull();
});

it('restores scrolling and disposes its instance when unmounted while open', () => {
    const alert = mountAlert();
    const element = wrapper.element;
    expect(Modal.getInstance(element)).not.toBeNull();

    wrapper.unmount();
    wrapper = null;
    expect(Modal.getInstance(element)).toBeNull();
    expect(document.body.classList.contains('modal-open')).toBe(false);
    expect(document.body.style.overflow).toBe('');
    expect(document.querySelector('.modal-backdrop')).toBeNull();

    alert.type = 'success';
    element.dispatchEvent(new Event('hidden.bs.modal'));
    expect(alert.type).toBe('success');
});

it('closes automatically after the configured delay', async () => {
    vi.useFakeTimers();
    mountAlert({ type: 'success', autoClose: 1000 });
    await vi.advanceTimersByTimeAsync(1000);
    expect(document.body.classList.contains('modal-open')).toBe(false);
});

it('preserves an underlying modal when the confirmation closes', async () => {
    const underlyingElement = document.createElement('div');
    underlyingElement.className = 'modal';
    underlyingElement.innerHTML = '<div class="modal-dialog"><div class="modal-content"></div></div>';
    document.body.appendChild(underlyingElement);
    const underlyingModal = new Modal(underlyingElement);

    try {
        underlyingModal.show();
        mountAlert();
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Cancel')
            .trigger('click');
        expect(underlyingElement.classList.contains('show')).toBe(true);
        expect(document.body.classList.contains('modal-open')).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');
        wrapper.unmount();
        wrapper = null;
    } finally {
        underlyingModal.hide();
        underlyingModal.dispose();
        underlyingElement.remove();
    }

    expect(document.body.classList.contains('modal-open')).toBe(false);
    expect(document.body.style.overflow).toBe('');
});
