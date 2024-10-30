(()=>{"use strict";var e,t,n,r={721:(e,t,n)=>{Object.defineProperty(t,"__esModule",{value:!0}),t.default=function(e,t,n){var r=(arguments.length>3&&void 0!==arguments[3]?arguments[3]:null)||React.createElement(React.Fragment,null);return{name:e,label:React.createElement(a.default,{text:t,icon:n}),ariaLabel:t,content:r,edit:r,canMakePayment:function(){return!0},paymentMethodId:e,supports:{showSavedCards:!1,showSaveOption:!1}}};var r,a=(r=n(468))&&r.__esModule?r:{default:r}},505:(e,t,n)=>{Object.defineProperty(t,"__esModule",{value:!0}),t.default=void 0,n(736);var r,a=n(307),o=n(818),l=n(360),i=(r=n(721))&&r.__esModule?r:{default:r};function u(e,t){(null==t||t>e.length)&&(t=e.length);for(var n=0,r=new Array(t);n<t;n++)r[n]=e[n];return r}var c=wc.wcSettings.getSetting("billie_data"),s=function(e){var t,n,r=e.eventRegistration,l=r.onPaymentSetup,i=r.onCheckoutValidation,c=e.emitResponse.responseTypes,s=e.methodDescription,d=(0,o.select)(wc.wcBlocksData.CART_STORE_KEY).getCartData().billingAddress,f=(t=(0,a.useState)(null),n=2,function(e){if(Array.isArray(e))return e}(t)||function(e,t){var n=null==e?null:"undefined"!=typeof Symbol&&e[Symbol.iterator]||e["@@iterator"];if(null!=n){var r,a,o,l,i=[],u=!0,c=!1;try{if(o=(n=n.call(e)).next,0===t){if(Object(n)!==n)return;u=!1}else for(;!(u=(r=o.call(n)).done)&&(i.push(r.value),i.length!==t);u=!0);}catch(e){c=!0,a=e}finally{try{if(!u&&null!=n.return&&(l=n.return(),Object(l)!==l))return}finally{if(c)throw a}}return i}}(t,n)||function(e,t){if(e){if("string"==typeof e)return u(e,t);var n=Object.prototype.toString.call(e).slice(8,-1);return"Object"===n&&e.constructor&&(n=e.constructor.name),"Map"===n||"Set"===n?Array.from(e):"Arguments"===n||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(n)?u(e,t):void 0}}(t,n)||function(){throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")}()),p=f[0],m=f[1];return(0,a.useEffect)((function(){return i((function(){return d.company?(m(null),!0):(m("Diese Zahlart ist nur für Geschäftskunden verfügbar."),!1)}))}),[i,d]),(0,a.useEffect)((function(){return l((function(){return p?{type:c.ERROR,message:p}:{type:c.SUCCESS}}))}),[l,p]),React.createElement("p",null,s)};t.default=(0,i.default)("billie",c.title,c.hide_logo?"":"".concat(l.BILLIE_ASSETS_URL,"/billie_logo_large.svg"),React.createElement(s,{methodDescription:c.method_description}))},468:(e,t)=>{Object.defineProperty(t,"__esModule",{value:!0}),t.default=function(e){var t=e.text,n=e.icon;return React.createElement("span",{style:{display:"flex",alignItems:"center",justifyContent:"space-between",paddingRight:"16px",width:"100%",gap:"16px"}},React.createElement("strong",null,t),n?React.createElement("img",{src:n,alt:t}):null)}},360:(e,t)=>{Object.defineProperty(t,"__esModule",{value:!0}),t.BILLIE_ASSETS_URL=void 0,t.BILLIE_ASSETS_URL="/wp-content/plugins/woocommerce-billie/ressources"},613:e=>{e.exports=window.wc.wcBlocksRegistry},818:e=>{e.exports=window.wp.data},307:e=>{e.exports=window.wp.element},736:e=>{e.exports=window.wp.i18n}},a={};function o(e){var t=a[e];if(void 0!==t)return t.exports;var n=a[e]={exports:{}};return r[e](n,n.exports,o),n.exports}t=o(613),n=(e=o(505))&&e.__esModule?e:{default:e},(0,t.registerPaymentMethod)(n.default)})();