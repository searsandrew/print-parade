namespace PrintParade.Bridge.Core;

public interface IPrintSpooler
{
    Task PrintAsync(ClaimedPrintJob job, CancellationToken cancellationToken = default);
}
