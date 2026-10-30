/*
 * hostily Captcha: Darstellung des Elements "hostily Captcha" im Form-Editor (Bereich "Stage").
 * Der Form-Editor kennt nur seine eigenen Elementtypen; fuer weitere Typen zeichnet dieses Modul die einfache Vorlage.
 */
import * as Stage from '@typo3/form/backend/form-editor/stage-component.js';

export function bootstrap(formEditorApp) {
    formEditorApp.getPublisherSubscriber().subscribe(
        'view/stage/abstract/render/template/perform',
        (topic, args) => {
            const [formElement, template] = args;
            if (formElement.get('type') === 'HostilyCaptcha') {
                Stage.renderSimpleTemplate(formElement, template);
            }
        },
    );
}
