import {__} from '@wordpress/i18n';
import {useEffect, useState} from '@wordpress/element';
import {select} from '@wordpress/data';
import {BILLIE_ASSETS_URL} from '../../constants';
import getPaymentMethodConfig from './getPaymentMethodConfig';

const settings = wc.wcSettings.getSetting('billie_data');

const Billie = (props) => {
    const {
        eventRegistration: {onPaymentSetup, onCheckoutValidation},
        emitResponse: {responseTypes},
        methodDescription,
    } = props;

    const store = select(wc.wcBlocksData.CART_STORE_KEY);
    const {billingAddress} = store.getCartData();

    const [errorMessage, setErrorMessage] = useState(null);

    useEffect(() => onCheckoutValidation(() => {
        if (!billingAddress.company) {
            setErrorMessage('Diese Zahlart ist nur für Geschäftskunden verfügbar.');
            return false;
        }

        setErrorMessage(null);
        return true;
    }), [onCheckoutValidation, billingAddress]);

    useEffect(() => onPaymentSetup(() => {
        if (errorMessage) {
            return {
                type: responseTypes.ERROR,
                message: errorMessage,
            };
        }

        return {
            type: responseTypes.SUCCESS,
        };
    }), [onPaymentSetup, errorMessage]);

    return (
        <p>{methodDescription}</p>
    );
};

export default getPaymentMethodConfig(
    'billie',
    settings.title,
    !settings.hide_logo ? `${BILLIE_ASSETS_URL}/billie_logo_large.svg` : '',
    <Billie methodDescription={settings.method_description}/>,
);
