import {registerPaymentMethod} from '@woocommerce/blocks-registry';
import Invoice from './invoice';

registerPaymentMethod(Invoice);
