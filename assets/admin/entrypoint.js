import { Application } from '@hotwired/stimulus';

import PrintDialogController from './controllers/print-dialog_controller';
import './scss/print-dialog.scss';

// The plugin runs its own Stimulus application so it works whatever the host application registers.
const application = Application.start();
application.register('zangra-barcode-print', PrintDialogController);
