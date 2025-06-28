describe('CCM CMS Admin', () => {
  beforeEach(() => {
    cy.db_createCms({ name: 'Test CMS' });
    cy.doAdministratorLogin();
    cy.visit('/administrator/index.php?option=com_ccm&view=cmss');
    cy.get('table').contains('Test CMS').click();
  });

  afterEach(() => {
    cy.task('queryDB', "DELETE FROM #__ccm_cms WHERE name = 'Test CMS'");
  });

  it('can edit a CMS', () => {
    cy.get('input[name="jform[url]"]').clear().type('https://testcms.example.com');
    cy.clickToolbarButton('Save');
    cy.checkForSystemMessage('Item saved.');
    cy.get('input[name="jform[url]"]').should('have.value', 'https://testcms.example.com');
  });

  it('can edit a CMS and close', () => {
    cy.get('input[name="jform[url]"]').clear().type('https://testcms.example.com');
    cy.clickToolbarButton('Save & Close');
    cy.checkForSystemMessage('Item saved.');
    cy.get('table').contains('Test CMS').click();
    cy.get('input[name="jform[url]"]').should('have.value', 'https://testcms.example.com');
  });

  it('can edit a CMS name, save, and check the name is updated', () => {
    cy.get('input[name="jform[name]"]').clear().type('A Test CMS');
    cy.get('input[name="jform[url]"]').clear().type('https://testcms.example.com');
    cy.clickToolbarButton('Save');
    cy.checkForSystemMessage('Item saved.');
    cy.get('input[name="jform[name]"]').should('have.value', 'A Test CMS');

    // return the old name to be removed in the afterEach
    cy.get('input[name="jform[name]"]').clear().type('Test CMS');
    cy.clickToolbarButton('Save');
  });

  it('can edit a CMS name, with empty url', () => {
    cy.clickToolbarButton('Save');
    cy.checkForSystemMessage(/The form cannot be submitted as it's missing required data./i);
  });
});